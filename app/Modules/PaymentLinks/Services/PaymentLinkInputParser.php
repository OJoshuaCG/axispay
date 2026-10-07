<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\Fx\Enums\FxMode;
use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\PaymentLinks\Data\CreatePaymentLinkData;
use App\Modules\PaymentLinks\Data\LineItem;
use App\Modules\PaymentLinks\Exceptions\PaymentLinkRejectedException;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Http\JsonBody;
use App\Modules\Shared\Money\AmountParser;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Time\IsoDateTime;
use App\Modules\Tenancy\Enums\CheckoutLocale;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Field rules of `POST /v1/payment_links` (plan 8.2, 10.5), shared by the API
 * and the panel's manual form. Only the shape of each field is checked here;
 * CreatePaymentLink checks what depends on the tenant, the gateway and the
 * clock. The first failing field is reported with its error code and `param`
 * (plan 10.4).
 */
final readonly class PaymentLinkInputParser
{
    public const int DESCRIPTION_MAX = 500;

    public const int CLIENT_REFERENCE_MAX = 200;

    public const int METADATA_MAX_KEYS = 20;

    public const int METADATA_VALUE_MAX = 500;

    public const int RETURN_URL_MAX = 2048;

    public const int LINE_ITEMS_MAX = 20;

    public const int LINE_ITEM_LABEL_MAX = 100;

    private const array FIELDS = [
        'amount', 'currency', 'description', 'metadata', 'client_reference_id', 'expires_in_hours',
        'expires_at', 'fx', 'payer_fields', 'return_url', 'auto_redirect', 'locale', 'pre_payment_validation', 'line_items',
    ];

    private const string METADATA_KEY_PATTERN = '/^[A-Za-z0-9_\-]{1,40}$/D';

    public function __construct(private AmountParser $amounts) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ApiException PaymentLinkRejectedException, or InvalidAmountException for amount and currency
     */
    public function parse(array $input): CreatePaymentLinkData
    {
        foreach (array_keys($input) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, "Received unknown parameter: {$field}.", (string) $field);
            }
        }

        // Fields are checked in the documented order (ADR-0048 §6), so the
        // first failing one is always the same for the same request.
        $amount = $this->amounts->parse($input['amount'] ?? null, $input['currency'] ?? null);
        $description = $this->description($input['description'] ?? null);
        $metadata = $this->metadata($input['metadata'] ?? null);
        $lineItems = $this->lineItems($input['line_items'] ?? null, $amount);
        $clientReferenceId = $this->optionalString($input['client_reference_id'] ?? null, 'client_reference_id', self::CLIENT_REFERENCE_MAX);
        [$expiresInHours, $expiresAt] = $this->expiration($input);
        [$fxMode, $fxRate] = $this->fx($input['fx'] ?? null);
        $payerFields = $this->payerFields($input['payer_fields'] ?? null);
        $returnUrl = $this->returnUrl($input['return_url'] ?? null);

        return new CreatePaymentLinkData(
            amount: $amount,
            description: $description,
            metadata: $metadata,
            clientReferenceId: $clientReferenceId,
            expiresInHours: $expiresInHours,
            expiresAt: $expiresAt,
            fxMode: $fxMode,
            fxRate: $fxRate,
            payerFields: $payerFields,
            returnUrl: $returnUrl,
            locale: $this->locale($input['locale'] ?? null),
            prePaymentValidation: $this->optionalBool($input['pre_payment_validation'] ?? null, 'pre_payment_validation'),
            lineItems: $lineItems,
            autoRedirect: $this->autoRedirect($input['auto_redirect'] ?? null, $returnUrl),
        );
    }

    private function description(mixed $value): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterMissing, 'Missing required parameter: description.', 'description');
        }

        if (! is_string($value) || mb_strlen(trim($value)) > self::DESCRIPTION_MAX) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'The description must be a string of 1 to '.self::DESCRIPTION_MAX.' characters.', 'description');
        }

        return trim($value);
    }

    /**
     * Keys are strings even when they look like numbers (`"123"`, `"0"`).
     *
     * @return array<array-key, string>|null
     */
    private function metadata(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $invalid = static fn (string $message): PaymentLinkRejectedException => PaymentLinkRejectedException::of(ApiErrorCode::MetadataInvalid, $message, 'metadata');
        $entries = JsonBody::objectEntries($value) ?? throw $invalid('The metadata must be an object of string values.');

        if (count($entries) > self::METADATA_MAX_KEYS) {
            throw $invalid('The metadata can have at most '.self::METADATA_MAX_KEYS.' keys.');
        }

        $metadata = [];

        foreach ($entries as [$key, $item]) {
            if (preg_match(self::METADATA_KEY_PATTERN, $key) !== 1) {
                throw $invalid('Metadata keys must be 1 to 40 characters from A-Z, a-z, 0-9, "_" and "-".');
            }

            if (! is_string($item) || mb_strlen($item) > self::METADATA_VALUE_MAX) {
                throw $invalid("The metadata value of '{$key}' must be a string of at most ".self::METADATA_VALUE_MAX.' characters (no numbers, booleans or nested objects).');
            }

            $metadata[$key] = $item;
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * The merchant's breakdown of the amount (ADR-0064): a non-empty list of
     * `{label, amount, absorbs_rounding}`, in the link's currency, adding up
     * to the link amount, with at most one line that absorbs the rounding of
     * a conversion. Whether that line is required depends on the tenant and
     * is checked by CreatePaymentLink.
     *
     * @return list<LineItem>
     */
    private function lineItems(mixed $value, Money $total): array
    {
        if ($value === null) {
            return [];
        }

        $invalid = static fn (string $message, string $param = 'line_items'): PaymentLinkRejectedException => PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, $message, $param);

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw $invalid('line_items must be a non-empty list of {label, amount, absorbs_rounding} objects.');
        }

        if (count($value) > self::LINE_ITEMS_MAX) {
            throw $invalid('line_items can have at most '.self::LINE_ITEMS_MAX.' items.');
        }

        $items = [];
        $sumMinor = 0;
        $absorbing = 0;

        foreach ($value as $index => $raw) {
            $item = $this->lineItem($raw, "line_items.{$index}", $total->currency, $invalid);
            $items[] = $item;
            $sumMinor += $item->amount->minorAmount;
            $absorbing += $item->absorbsRounding ? 1 : 0;
        }

        if ($absorbing > 1) {
            throw $invalid('At most one line item can have absorbs_rounding true.');
        }

        if ($sumMinor !== $total->minorAmount) {
            throw $invalid('The line items must add up to the link amount ('.$total->toDecimalString().' '.$total->currency->value.'); they add up to '.Money::ofMinor($sumMinor, $total->currency)->toDecimalString().'.');
        }

        return $items;
    }

    /**
     * @param  Closure(string, string=): PaymentLinkRejectedException  $invalid
     */
    private function lineItem(mixed $raw, string $path, CurrencyCode $currency, Closure $invalid): LineItem
    {
        $entries = JsonBody::objectEntries($raw) ?? throw $invalid('Each line item must be an object with label, amount and, optionally, absorbs_rounding.', $path);
        $fields = [];

        foreach ($entries as [$key, $item]) {
            if (! in_array($key, ['label', 'amount', 'absorbs_rounding'], true)) {
                throw $invalid("Received unknown parameter: {$path}.{$key}.", "{$path}.{$key}");
            }

            $fields[$key] = $item;
        }

        $label = $fields['label'] ?? null;

        if (! is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > self::LINE_ITEM_LABEL_MAX) {
            throw $invalid('The label of a line item must be a string of 1 to '.self::LINE_ITEM_LABEL_MAX.' characters.', "{$path}.label");
        }

        $amount = $this->amounts->parsePart($fields['amount'] ?? null, $currency, "{$path}.amount");
        $absorbs = $fields['absorbs_rounding'] ?? false;

        if (! is_bool($absorbs)) {
            throw $invalid('absorbs_rounding must be true or false.', "{$path}.absorbs_rounding");
        }

        return new LineItem(trim($label), $amount, $absorbs);
    }

    private function autoRedirect(mixed $value, ?string $returnUrl): bool
    {
        $requested = $this->optionalBool($value, 'auto_redirect');

        if ($requested === true && $returnUrl === null) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'auto_redirect needs a return_url.', 'auto_redirect');
        }

        return $requested ?? false;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{0: ?int, 1: ?CarbonImmutable}
     */
    private function expiration(array $input): array
    {
        $hours = $input['expires_in_hours'] ?? null;
        $at = $input['expires_at'] ?? null;

        if ($hours !== null && $at !== null) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'Send either expires_in_hours or expires_at, not both.', 'expires_at');
        }

        if ($hours !== null && ! is_int($hours)) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'expires_in_hours must be an integer number of hours.', 'expires_in_hours');
        }

        if (is_int($hours) && $hours < 1) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ExpirationOutOfRange, 'expires_in_hours must be at least 1.', 'expires_in_hours');
        }

        if ($at === null) {
            return [$hours, null];
        }

        $parsed = IsoDateTime::parse($at)
            ?? throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'expires_at must be a valid ISO-8601 date-time with a time zone, e.g. 2026-09-26T18:30:00Z.', 'expires_at');

        return [null, $parsed];
    }

    /**
     * @return array{0: ?FxMode, 1: ?ExchangeRate}
     */
    private function fx(mixed $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        $entries = JsonBody::objectEntries($value)
            ?? throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'fx must be an object with "mode" and, for "fixed", "rate".', 'fx');
        $fields = [];

        foreach ($entries as [$key, $item]) {
            if (! in_array($key, ['mode', 'rate'], true)) {
                throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, "Received unknown parameter: fx.{$key}.", "fx.{$key}");
            }

            $fields[$key] = $item;
        }

        $modeValue = $fields['mode'] ?? null;
        $rateValue = $fields['rate'] ?? null;

        if ($modeValue === null) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterMissing, 'Missing required parameter: fx.mode.', 'fx.mode');
        }

        $mode = is_string($modeValue) ? FxMode::tryFrom($modeValue) : null;

        if ($mode === null) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'fx.mode must be one of: '.implode(', ', array_map(static fn (FxMode $mode): string => $mode->value, FxMode::cases())).'.', 'fx.mode');
        }

        if ($mode !== FxMode::Fixed) {
            if ($rateValue !== null) {
                throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'fx.rate is only accepted with fx.mode "fixed".', 'fx.rate');
            }

            return [$mode, null];
        }

        if ($rateValue === null) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterMissing, 'Missing required parameter: fx.rate (required with fx.mode "fixed").', 'fx.rate');
        }

        $rate = ExchangeRate::tryOf($rateValue);

        if ($rate === null) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'fx.rate must be a decimal string greater than zero with up to 6 decimals, e.g. "17.250000".', 'fx.rate');
        }

        return [$mode, $rate];
    }

    /**
     * @return array<string, PayerFieldRequirement>
     */
    private function payerFields(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $entries = JsonBody::objectEntries($value)
            ?? throw PaymentLinkRejectedException::of(ApiErrorCode::PayerFieldInvalid, 'payer_fields must be an object of field => hidden|optional|required.', 'payer_fields');
        $fields = [];

        foreach ($entries as [$field, $requirement]) {
            $known = PayerField::tryFrom($field);

            if ($known === null) {
                throw PaymentLinkRejectedException::of(ApiErrorCode::PayerFieldInvalid, 'Unknown payer field. Allowed: '.implode(', ', PayerField::values()).'.', 'payer_fields.'.$field);
            }

            $parsed = is_string($requirement) ? PayerFieldRequirement::tryFrom($requirement) : null;

            if ($parsed === null) {
                throw PaymentLinkRejectedException::of(ApiErrorCode::PayerFieldInvalid, "payer_fields.{$known->value} must be one of: ".implode(', ', array_map(static fn (PayerFieldRequirement $r): string => $r->value, PayerFieldRequirement::cases())).'.', 'payer_fields.'.$known->value);
            }

            $fields[$known->value] = $parsed;
        }

        return $fields;
    }

    private function returnUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $invalid = PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'return_url must be an absolute http(s) URL of at most '.self::RETURN_URL_MAX.' characters.', 'return_url');

        if (! is_string($value) || $value === '' || strlen($value) > self::RETURN_URL_MAX || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw $invalid;
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw $invalid;
        }

        return $value;
    }

    private function locale(mixed $value): ?CheckoutLocale
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? CheckoutLocale::tryFrom($value) : null)
            ?? throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'locale must be one of: '.implode(', ', CheckoutLocale::values()).'.', 'locale');
    }

    private function optionalString(mixed $value, string $param, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '' || mb_strlen($value) > $max) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, "{$param} must be a string of 1 to {$max} characters.", $param);
        }

        return $value;
    }

    private function optionalBool(mixed $value, string $param): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (! is_bool($value)) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, "{$param} must be true or false.", $param);
        }

        return $value;
    }
}
