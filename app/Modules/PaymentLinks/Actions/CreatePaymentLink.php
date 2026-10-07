<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Fx\Enums\FxMode;
use App\Modules\Gateways\Services\ChargeReadiness;
use App\Modules\Identity\Models\User;
use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PaymentLinks\Data\CreatePaymentLinkData;
use App\Modules\PaymentLinks\Data\CreationContext;
use App\Modules\PaymentLinks\Data\LineItem;
use App\Modules\PaymentLinks\Enums\CreatedVia;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Exceptions\PaymentLinkRejectedException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\LinkConversionCheck;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Ids\SecureToken;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\IsoDateTime;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Services\ValidationEndpoints;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creates a payment link for the current tenant and mode (plan 10.5, 9.1
 * `— → active`), from the API or the panel. The data was shape-checked by
 * PaymentLinkInputParser; this action applies the rules that depend on the
 * tenant, its gateway connection and the clock, in this order:
 *
 *  - the tenant may create links: `active` or `grace` (plan 21.3;
 *    `suspended`/`closed` → `tenant_suspended`, `pending_onboarding` →
 *    `gateway_not_ready`);
 *  - a connection in this mode can charge (`gateway_not_ready`);
 *  - conversion only for USD links and only when the tenant enabled it
 *    (`fx_not_available`); omitted, a USD link of a tenant with conversion on
 *    takes the tenant's default mode. A converting link must have a usable
 *    rate (`fx_rate_invalid`) and reach the MXN minimum once converted
 *    (`amount_below_minimum_after_conversion`), see LinkConversionCheck;
 *  - line items on a link that can be converted: exactly one line absorbs the
 *    rounding (`parameter_invalid` on `line_items`, ADR-0064);
 *  - the tenant's amount cap, the expiration range, the return URL domain
 *    and pre-payment validation (plan 15.8.1: `true` needs a validation URL
 *    in this mode, else `validation_endpoint_not_configured`; omitted, the
 *    URL's `enabled_by_default` decides; frozen on the link).
 *
 * A link with the same idempotency key in this tenant and mode is returned
 * as is when the request body is the same, and `422 idempotency_key_reused`
 * when it differs, even after the idempotency record expired (defense in
 * depth under the idempotency records, plan 7.5, ADR-0048).
 */
final readonly class CreatePaymentLink
{
    private const int TOKEN_BYTES = 32;

    public function __construct(
        private TenantContext $context,
        private ChargeReadiness $readiness,
        private AuditLogger $audit,
        private TenantAccess $access,
        private ValidationEndpoints $validationEndpoints,
        private LinkConversionCheck $conversionCheck,
    ) {}

    /**
     * Panel entry point: a panel that is not read-only (plan 21.3), then
     * `links:create` through the policy (impersonation is denied there, plan
     * 17.4), then the same rules as the API.
     */
    public function handleForUser(User $user, CreatePaymentLinkData $data): PaymentLink
    {
        if (! $this->access->panelWritable($user->tenant_id)) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::TenantSuspended, 'The account is read-only in its current state.');
        }

        Gate::forUser($user)->authorize('create', PaymentLink::class);

        return $this->handle($data, new CreationContext(CreatedVia::Panel, Actor::user($user->id)));
    }

    public function handle(CreatePaymentLinkData $data, CreationContext $creation): PaymentLink
    {
        if (($existing = $this->existing($creation)) !== null) {
            return $existing;
        }

        // Read fresh on purpose, not through TenantAccess's per-request memory:
        // the status must be the latest one right before the link is inserted
        // (a suspension saved a moment ago must already refuse it).
        $tenant = Tenant::query()->findOrFail($this->context->idOrFail(PaymentLink::class));
        $settings = $tenant->settings();
        $livemode = $this->context->livemode();

        $this->assertTenantCanCreate($tenant);
        $this->assertGatewayReady();
        $fxMode = $this->resolveFx($data, $settings);
        $this->assertLineItemsFitConversion($data, $fxMode);
        $this->conversionCheck->assertAcceptable($data->amount, $fxMode, $data->fxRate, $settings);
        $this->assertAmountWithinTenantCap($data->amount, $settings);
        $expiresAt = $this->expiresAt($data, $settings);
        $this->assertReturnUrlAllowed($data->returnUrl, $tenant, $livemode);
        $prePaymentValidation = $this->prePaymentValidation($data->prePaymentValidation);

        try {
            return DB::transaction(function () use ($data, $creation, $settings, $livemode, $fxMode, $expiresAt, $prePaymentValidation): PaymentLink {
                // Re-checked under a shared lock on the connection row: a
                // disconnection committed since the first check is seen here,
                // and one that starts now waits for this insert (then cancels
                // the new link with the others).
                if (! $this->readiness->canChargeInCurrentModeLocked()) {
                    throw self::gatewayNotReady();
                }

                $link = new PaymentLink;
                $link->forceFill([
                    'livemode' => $livemode,
                    'public_token' => SecureToken::base62(self::TOKEN_BYTES),
                    'status' => PaymentLinkStatus::Active,
                    'amount_minor' => $data->amount->minorAmount,
                    'currency' => $data->amount->currency,
                    'description' => $data->description,
                    'metadata' => $data->metadata,
                    'line_items' => $this->lineItemsForStorage($data),
                    'client_reference_id' => $data->clientReferenceId,
                    'fx_mode' => $fxMode,
                    'fx_fixed_rate' => $fxMode === FxMode::Fixed ? $data->fxRate?->toString() : null,
                    'payer_fields_config' => $this->payerFields($data, $settings),
                    'return_url' => $data->returnUrl,
                    'auto_redirect' => $data->autoRedirect,
                    'pre_payment_validation' => $prePaymentValidation,
                    'locale' => ($data->locale ?? $settings->checkoutLocale)->value,
                    'expires_at' => $expiresAt,
                    'created_via' => $creation->via,
                    'created_by_actor_type' => $creation->actor->type,
                    'created_by_actor_id' => $creation->actor->id,
                    'idempotency_key' => $creation->idempotencyKey,
                    'idempotency_request_hash' => $creation->idempotencyKey !== null ? $creation->requestHash : null,
                ])->save();

                $this->audit->record(AuditAction::PaymentLinkCreated, $link, [
                    'amount' => $link->money()->toDecimalString(),
                    'currency' => $link->currency->value,
                    'via' => $creation->via->value,
                    'livemode' => $livemode,
                    'expires_at' => IsoDateTime::format($expiresAt),
                ], actor: $creation->actor);

                // Every column has its value in memory (model defaults, the
                // timestamps set by save()), so no re-read is needed.
                return $link;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same idempotency key won the race.
            return $this->existing($creation) ?? throw $e;
        }
    }

    private function existing(CreationContext $creation): ?PaymentLink
    {
        if ($creation->idempotencyKey === null) {
            return null;
        }

        $link = PaymentLink::query()->where('idempotency_key', $creation->idempotencyKey)->first();

        if ($link !== null
            && $creation->requestHash !== null
            && $link->idempotency_request_hash !== null
            && ! hash_equals($link->idempotency_request_hash, $creation->requestHash)) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::IdempotencyKeyReused);
        }

        return $link;
    }

    private function assertTenantCanCreate(Tenant $tenant): void
    {
        if ($tenant->status->allowsLinkCreation()) {
            return;
        }

        throw $tenant->status === TenantStatus::PendingOnboarding
            ? PaymentLinkRejectedException::of(ApiErrorCode::GatewayNotReady, 'The account has not finished connecting a payment gateway, so it cannot create payment links yet.')
            : PaymentLinkRejectedException::of(ApiErrorCode::TenantSuspended, 'The account cannot create payment links in its current state. Existing links keep working.');
    }

    private function assertGatewayReady(): void
    {
        if (! $this->readiness->canChargeInCurrentMode()) {
            throw self::gatewayNotReady();
        }
    }

    private static function gatewayNotReady(): PaymentLinkRejectedException
    {
        return PaymentLinkRejectedException::of(
            ApiErrorCode::GatewayNotReady,
            'No payment gateway account in this mode can accept charges. Connect Stripe (or finish its requirements) for this mode first.',
        );
    }

    /**
     * Plan 10.5 business validations 3 and 4. Omitted: a USD link of a tenant
     * with conversion on takes the tenant's default mode (`banxico_fix` or
     * `fixed`); otherwise `none`.
     */
    private function resolveFx(CreatePaymentLinkData $data, TenantSettings $settings): FxMode
    {
        $isUsd = $data->amount->currency === CurrencyCode::USD;

        if ($data->fxMode === null) {
            return $isUsd && $settings->fxConversionEnabled ? $settings->fxDefaultMode : FxMode::None;
        }

        if ($data->fxMode === FxMode::None) {
            return FxMode::None;
        }

        if (! $isUsd) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::FxNotAvailable, 'Currency conversion only applies to USD links; this link is already in MXN.', 'fx.mode');
        }

        if (! $settings->fxConversionEnabled) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::FxNotAvailable, 'Currency conversion is not enabled for this account.', 'fx.mode');
        }

        return $data->fxMode;
    }

    /**
     * ADR-0064: a link that can be converted to MXN needs exactly one line
     * that absorbs the rounding, or its converted lines could not add up to
     * the amount charged.
     */
    private function assertLineItemsFitConversion(CreatePaymentLinkData $data, FxMode $fxMode): void
    {
        if ($data->lineItems === [] || ! $fxMode->converts()) {
            return;
        }

        $absorbing = count(array_filter($data->lineItems, static fn (LineItem $item): bool => $item->absorbsRounding));

        if ($absorbing !== 1) {
            throw PaymentLinkRejectedException::of(
                ApiErrorCode::ParameterInvalid,
                'This link can be converted to MXN, so exactly one line item must have absorbs_rounding true: it takes the rounding residual.',
                'line_items',
            );
        }
    }

    /**
     * @return list<array{label: string, amount_minor: int, absorbs_rounding: bool}>|null
     */
    private function lineItemsForStorage(CreatePaymentLinkData $data): ?array
    {
        if ($data->lineItems === []) {
            return null;
        }

        return array_map(
            static fn (LineItem $item): array => ['label' => $item->label, 'amount_minor' => $item->amount->minorAmount, 'absorbs_rounding' => $item->absorbsRounding],
            $data->lineItems,
        );
    }

    private function assertAmountWithinTenantCap(Money $amount, TenantSettings $settings): void
    {
        $cap = $settings->maxAmountMinorFor($amount->currency);

        if ($cap !== null && $amount->minorAmount > $cap) {
            $max = Money::ofMinor($cap, $amount->currency)->toDecimalString();

            throw PaymentLinkRejectedException::of(ApiErrorCode::AmountAboveMaximum, "The amount is above the maximum of {$max} {$amount->currency->value}.", 'amount');
        }
    }

    /**
     * Plan 10.5 / ADR-0048: at least 15 minutes ahead, at most the tenant's
     * maximum (never above 60 days); the tenant's default when omitted.
     */
    private function expiresAt(CreatePaymentLinkData $data, TenantSettings $settings): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $param = $data->expiresAt !== null ? 'expires_at' : 'expires_in_hours';
        $expiresAt = $data->expiresAt ?? $now->addHours($data->expiresInHours ?? $settings->defaultExpirationHours);

        $minMinutes = config()->integer('axispay.limits.min_expiration_minutes');
        $maxHours = $settings->maxExpirationHours;

        if ($expiresAt->lessThan($now->addMinutes($minMinutes))
            || $expiresAt->greaterThan($now->addHours($maxHours))) {
            throw PaymentLinkRejectedException::of(
                ApiErrorCode::ExpirationOutOfRange,
                "The expiration must be between {$minMinutes} minutes and {$maxHours} hours from now.",
                $param,
            );
        }

        return $expiresAt;
    }

    /**
     * Plan 10.5: HTTPS in live mode, and the host must be one of the
     * tenant's allowed return domains (no open redirects).
     */
    private function assertReturnUrlAllowed(?string $url, Tenant $tenant, bool $livemode): void
    {
        if ($url === null) {
            return;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            array_filter($tenant->allowed_return_domains ?? [], is_string(...)),
        );

        if ($livemode && $scheme !== 'https') {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ReturnUrlNotAllowed, 'return_url must use HTTPS in live mode.', 'return_url');
        }

        if (! in_array($host, $allowed, true)) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ReturnUrlNotAllowed, "The domain '{$host}' is not in the account's allowed return domains.", 'return_url');
        }
    }

    /**
     * Plan 15.8.1: whether the link uses the pre-payment validation. `true`
     * needs a validation URL in the current mode (else `400
     * validation_endpoint_not_configured`); `false` turns it off; omitted,
     * the URL's `enabled_by_default` decides (off without a URL). If the URL
     * is removed later, the link keeps asking for validation and is not
     * charged until one is configured again (ADR-0058).
     */
    private function prePaymentValidation(?bool $requested): bool
    {
        if ($requested === false) {
            return false;
        }

        $endpoint = $this->validationEndpoints->current();

        if ($requested === true && $endpoint === null) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ValidationEndpointNotConfigured, param: 'pre_payment_validation');
        }

        return $requested ?? ($endpoint->enabled_by_default ?? false);
    }

    /**
     * Effective configuration frozen on the link (plan 19.1): link override,
     * then tenant setting, then the platform default.
     *
     * @return array<string, string>
     */
    private function payerFields(CreatePaymentLinkData $data, TenantSettings $settings): array
    {
        $effective = [];

        foreach (PayerField::cases() as $field) {
            $effective[$field->value] = ($data->payerFields[$field->value] ?? $settings->payerFields[$field->value] ?? $field->platformDefault())->value;
        }

        return $effective;
    }
}
