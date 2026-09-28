<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\ReviewReason;
use Illuminate\Support\Facades\Lang;
use Locale;

/**
 * How the tenant panel shows an attempt's gateway details (plan 7.5): the
 * decline code as a translated label (the raw code stays visible next to
 * it for support), the card country in the reader's language, the card
 * brand by its commercial name, and why an attempt needs review.
 */
final class AttemptDisplay
{
    /** Commercial names of the card brands Stripe reports. */
    private const array BRANDS = [
        'amex' => 'American Express',
        'american_express' => 'American Express',
        'cartes_bancaires' => 'Cartes Bancaires',
        'diners' => 'Diners Club',
        'discover' => 'Discover',
        'eftpos_au' => 'eftpos',
        'interac' => 'Interac',
        'jcb' => 'JCB',
        'link' => 'Link',
        'mastercard' => 'Mastercard',
        'unionpay' => 'UnionPay',
        'visa' => 'Visa',
    ];

    /** The decline's label in the reader's language, or the raw code when unknown. */
    public static function declineLabel(string $code): string
    {
        $key = 'payments.decline_codes.'.$code;

        return Lang::has($key) ? (string) __($key) : $code;
    }

    public static function countryName(?string $country, ?string $locale = null): ?string
    {
        if ($country === null || $country === '') {
            return null;
        }

        $name = Locale::getDisplayRegion('-'.strtoupper($country), $locale ?? app()->getLocale());

        return is_string($name) && $name !== '' ? $name : strtoupper($country);
    }

    public static function brandName(?string $brand): string
    {
        $brand = strtolower((string) $brand);

        return self::BRANDS[$brand] ?? ucfirst(str_replace('_', ' ', $brand));
    }

    public static function reviewReason(?ReviewReason $reason): string
    {
        return (string) __('payments.review_reason.'.($reason ?? ReviewReason::ClosedWithoutGateway)->value);
    }
}
