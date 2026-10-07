<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Filament\Support;

use App\Modules\Tenancy\Data\TenantSettings;

/**
 * The panel's manual create form, named like the API parameters it feeds
 * (PaymentLinkInputParser applies the same rules to both). Converts the
 * form state into the API input:
 *
 *  - blank fields are left out;
 *  - the amount may carry thousands separators as shown on screen
 *    (`12,500.00`): they are removed here, in the panel only (the API keeps
 *    its strict format, plan 8.2);
 *  - the expiry is a preset number of hours, or `custom` with its own hours
 *    field;
 *  - metadata rows with an empty key are dropped.
 */
final class PaymentLinkForm
{
    /** Form fields named like their API parameter (refusals are shown on them). */
    public const array FIELDS = ['amount', 'currency', 'description', 'client_reference_id', 'expires_in_hours', 'locale', 'metadata'];

    /** Expiry presets in hours (24 h, 3, 7, 30 and 60 days; 60 days is the platform maximum, ADR-0048). */
    public const array EXPIRY_PRESETS = [24, 72, 168, 720, 1440];

    public const string CUSTOM_EXPIRY = 'custom';

    private const array TEXT_FIELDS = ['currency', 'description', 'client_reference_id', 'locale'];

    private function __construct() {}

    /**
     * Presets the tenant allows, in hours => label, then "custom".
     *
     * @return array<int|string, string>
     */
    public static function expiryOptions(TenantSettings $settings): array
    {
        $options = [];

        foreach (self::EXPIRY_PRESETS as $hours) {
            if ($hours <= $settings->maxExpirationHours) {
                $options[$hours] = $hours > 24
                    ? __('payment_links.expiry.days', ['count' => intdiv($hours, 24)])
                    : __('payment_links.expiry.hours', ['count' => $hours]);
            }
        }

        $options[self::CUSTOM_EXPIRY] = __('payment_links.expiry.custom');

        return $options;
    }

    /** The preset selected by default: the tenant's default when it is one, else the custom field. */
    public static function defaultExpiry(TenantSettings $settings): int|string
    {
        return in_array($settings->defaultExpirationHours, self::EXPIRY_PRESETS, true) && $settings->defaultExpirationHours <= $settings->maxExpirationHours
            ? $settings->defaultExpirationHours
            : self::CUSTOM_EXPIRY;
    }

    /**
     * @param  array<mixed>  $data  form state
     * @return array<string, mixed> API input
     */
    public static function toInput(array $data): array
    {
        $input = [];

        if (is_string($data['amount'] ?? null) && trim($data['amount']) !== '') {
            $input['amount'] = str_replace(',', '', trim($data['amount']));
        }

        foreach (self::TEXT_FIELDS as $field) {
            if (is_string($data[$field] ?? null) && trim($data[$field]) !== '') {
                $input[$field] = trim($data[$field]);
            }
        }

        $hours = self::hours($data);

        if ($hours !== null) {
            $input['expires_in_hours'] = $hours;
        }

        $metadata = [];

        foreach (is_array($data['metadata'] ?? null) ? $data['metadata'] : [] as $key => $value) {
            // PHP turns digit-only keys ("0") into integers: they are still keys.
            $key = trim((string) $key);

            if ($key !== '') {
                $metadata[$key] = is_scalar($value) ? (string) $value : '';
            }
        }

        if ($metadata !== []) {
            // An object even when every key looks like a number (parser rule).
            $input['metadata'] = (object) $metadata;
        }

        return $input;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function hours(array $data): ?int
    {
        $preset = $data['expiry'] ?? null;

        if ($preset !== null && $preset !== self::CUSTOM_EXPIRY && $preset !== '') {
            return is_numeric($preset) ? (int) $preset : null;
        }

        $hours = $data['expires_in_hours'] ?? null;

        return is_int($hours) || (is_string($hours) && preg_match('/^-?[0-9]{1,6}$/D', $hours) === 1) ? (int) $hours : null;
    }
}
