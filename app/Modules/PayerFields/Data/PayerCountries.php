<?php

declare(strict_types=1);

namespace App\Modules\PayerFields\Data;

use Collator;
use Locale;

/**
 * Countries offered by the checkout's phone and address fields (plan 19.1),
 * with their calling codes. A short list centred on the platform's market
 * (MX first, the default); names come from ICU in the payer's language.
 * Phase 8 (payer fields catalog) may widen it.
 */
final class PayerCountries
{
    public const string DEFAULT = 'MX';

    /** ISO 3166-1 alpha-2 => ITU calling code. */
    public const array CALLING_CODES = [
        'MX' => '52', 'US' => '1', 'CA' => '1', 'GT' => '502', 'SV' => '503', 'HN' => '504',
        'NI' => '505', 'CR' => '506', 'PA' => '507', 'CO' => '57', 'VE' => '58', 'EC' => '593',
        'PE' => '51', 'BO' => '591', 'CL' => '56', 'AR' => '54', 'UY' => '598', 'PY' => '595',
        'BR' => '55', 'DO' => '1', 'PR' => '1', 'CU' => '53', 'ES' => '34', 'GB' => '44',
        'FR' => '33', 'DE' => '49', 'IT' => '39', 'PT' => '351',
    ];

    public static function isSupported(string $code): bool
    {
        return array_key_exists($code, self::CALLING_CODES);
    }

    /**
     * @return array<string, string> code => display name in `$locale`, sorted by name (MX first)
     */
    public static function options(string $locale): array
    {
        $options = [];

        foreach (array_keys(self::CALLING_CODES) as $code) {
            if ($code !== self::DEFAULT) {
                $options[$code] = self::name($code, $locale);
            }
        }

        // Sorted as the payer's language sorts (accents, ñ), not by bytes.
        (new Collator($locale))->asort($options);

        return [self::DEFAULT => self::name(self::DEFAULT, $locale)] + $options;
    }

    private static function name(string $code, string $locale): string
    {
        $name = Locale::getDisplayRegion('-'.$code, $locale);

        return is_string($name) && $name !== '' ? $name : $code;
    }
}
