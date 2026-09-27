<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Support\Locales;
use Illuminate\Http\Request;

/**
 * Plan 11.3: the checkout speaks the link's language unless the payer chose
 * one with the language switcher (`?lang=` or the `locale` cookie, already
 * applied by SetLocale). An Accept-Language guess never overrides the link.
 */
final class CheckoutLocale
{
    public static function apply(Request $request, string $linkLocale): void
    {
        $chosen = Locales::match($request->query(Locales::QUERY)) ?? Locales::match($request->cookie(Locales::COOKIE));

        if ($chosen === null && Locales::isSupported($linkLocale)) {
            Locales::apply($linkLocale);
        }
    }

    /** Stripe Elements locale of the interface language (Mexican-style Spanish: es-419). */
    public static function stripe(string $locale): string
    {
        return $locale === 'es' ? 'es-419' : 'en';
    }
}
