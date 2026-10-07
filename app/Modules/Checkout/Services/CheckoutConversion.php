<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Checkout\Data\ChargePlan;
use App\Modules\Checkout\Data\CheckoutPaymentInput;
use App\Modules\Checkout\Data\CheckoutResult;
use App\Modules\Checkout\Data\CurrencyConfirmation;
use App\Modules\Checkout\Enums\CheckoutOutcome;
use App\Modules\Fx\Enums\ConversionBlockReason;
use App\Modules\Fx\Exceptions\FxUnavailableException;
use App\Modules\Fx\Models\FxQuote;
use App\Modules\Fx\Services\ConversionPolicy;
use App\Modules\Fx\Services\FxConverter;
use App\Modules\Fx\Services\FxQuoter;
use App\Modules\Fx\Services\LineItemConverter;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\PaymentLinks\Data\LineItem;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The currency step of "Pay" (plan 11.4, 13.2, 13.4, ADR-009, ADR-0063),
 * right after the card's country is read and before anything reaches the
 * gateway:
 *
 *  - no conversion needed: the link's own amount and currency;
 *  - conversion needed but the merchant cannot (tenant off, link opted out,
 *    fixed mode without a rate, stale or missing Banxico FIX, converted
 *    amount under the MXN minimum): nothing is charged, the payer is told
 *    and `checkout.conversion_unavailable` is recorded for the tenant;
 *  - conversion needed: the payer is shown the exact MXN amount (a quote)
 *    and nothing is charged until they confirm THAT quote. A quote that
 *    expired is replaced; if the amount did not change the payer's
 *    confirmation still counts, if it did they are asked again (plan 13.4).
 *
 * The quote id comes from the browser: it is only trusted when it is a quote
 * of this very link (tenant scope + link id) that converts this link's
 * amount to MXN, and only a literal `currency_confirmed: true` confirms.
 */
final readonly class CheckoutConversion
{
    /** At most one `conversion_unavailable` entry per link and hour (card testing must not flood the audit trail). */
    private const int UNAVAILABLE_AUDIT_TTL_SECONDS = 3_600;

    public function __construct(
        private ChargeAmount $amounts,
        private ConversionPolicy $policy,
        private FxQuoter $quoter,
        private TenantAccess $access,
        private AuditLogger $audit,
        private LineItemConverter $lineItems,
    ) {}

    public function plan(PaymentLink $link, GatewayConnection $connection, PaymentMethodPreview $card, CheckoutPaymentInput $input): ChargePlan
    {
        $settings = $this->access->settings($link->tenant_id);
        $decision = $this->policy->decide($link, $connection->country, $card->country, $settings);

        if ($decision->isBlocked()) {
            return $this->unavailable($link, $card, ($decision->blockReason ?? ConversionBlockReason::ConversionDisabled)->value);
        }

        if (! $decision->converts()) {
            return ChargePlan::charge($this->amounts->for($link, $card));
        }

        $previous = $this->previousQuote($link, $input->fxQuoteId);

        // The payer confirmed a quote that is still valid: charge exactly it.
        if ($input->currencyConfirmed && $previous !== null && ! $previous->isExpired()) {
            return ChargePlan::charge($previous->converted(), $previous);
        }

        try {
            $current = $this->quoter->current($link, $settings);
        } catch (FxUnavailableException $e) {
            return $this->unavailable($link, $card, $e->reason->value);
        }

        // An expired quote replaced by one with the same amount: the confirmation stands (plan 13.4).
        if ($input->currencyConfirmed && $previous !== null && $previous->converted()->equals($current->converted())) {
            return ChargePlan::charge($current->converted(), $current);
        }

        return ChargePlan::answer(new CheckoutResult(CheckoutOutcome::CurrencyConfirmationRequired, currencyConfirmation: new CurrencyConfirmation($current, $this->convertedLines($link, $current))));
    }

    /**
     * The link's line items in MXN, adding up to the quote's amount; none when
     * the link has none or they cannot be converted honestly (ADR-0064).
     *
     * @return list<LineItem>
     */
    private function convertedLines(PaymentLink $link, FxQuote $quote): array
    {
        $items = $link->lineItems();

        if ($items === []) {
            return [];
        }

        return $this->lineItems->convert($items, $quote->converted(), ExchangeRate::of($quote->effective_rate)) ?? [];
    }

    /** A quote of this link, converting its amount to MXN, or null (unknown, foreign, malformed ids are all null). */
    private function previousQuote(PaymentLink $link, ?string $quoteId): ?FxQuote
    {
        if ($quoteId === null || ! Ulid::isValid($quoteId)) {
            return null;
        }

        $quote = FxQuote::query()->where('payment_link_id', $link->id)->whereKey($quoteId)->first();

        return $quote !== null
            && $quote->converted_currency === FxConverter::TARGET
            && $quote->original()->equals($link->money())
                ? $quote
                : null;
    }

    private function unavailable(PaymentLink $link, PaymentMethodPreview $card, string $reason): ChargePlan
    {
        Log::warning('Checkout refused: the payment needs a currency conversion the merchant cannot offer.', ['payment_link_id' => $link->id, 'reason' => $reason]);

        if (Cache::add('axispay:checkout:conversion-unavailable:'.$link->id, true, self::UNAVAILABLE_AUDIT_TTL_SECONDS)) {
            // The reason and the card's country only: no card data (plan 11.7).
            $this->audit->record(AuditAction::CheckoutConversionUnavailable, $link, ['reason' => $reason, 'card_country' => $card->country], tenantId: $link->tenant_id);
        }

        return ChargePlan::answer(CheckoutResult::of(CheckoutOutcome::ConversionUnavailable));
    }
}
