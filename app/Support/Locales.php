<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Supported interface locales and how a chosen locale is applied.
 *
 * The list lives in config('app.supported_locales') (code => native name).
 * Currency is NOT part of the locale: a transaction carries its own currency,
 * the locale only decides how the viewer reads it (see docs/frontend/i18n.md).
 */
final class Locales
{
    /** Cookie that stores an explicit language choice. */
    public const COOKIE = 'locale';

    /** Query parameter that selects a language for the request (e.g. ?lang=es). */
    public const QUERY = 'lang';

    /** One year, in minutes. */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * @return array<string, string> locale code => native name
     */
    public static function supported(): array
    {
        return config('app.supported_locales', ['en' => 'English']);
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::supported());
    }

    /**
     * Return the locale when it is supported, otherwise null.
     */
    public static function match(mixed $locale): ?string
    {
        return self::isSupported($locale) ? $locale : null;
    }

    /**
     * Best supported match for the request's Accept-Language header, or null.
     * Only the primary language subtag is compared ("es-CL" matches "es").
     */
    public static function fromAcceptLanguage(Request $request): ?string
    {
        if (! $request->headers->has('Accept-Language')) {
            return null;
        }

        // getLanguages() is ordered by q-value and normalised to "es_CL".
        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(explode('_', $language)[0]);

            if (self::isSupported($primary)) {
                return $primary;
            }
        }

        return null;
    }

    /**
     * Apply a locale to the whole request: translator (which also updates
     * Carbon through Carbon's Laravel service provider listening to
     * LocaleUpdated) and the default locale of Illuminate\Support\Number.
     */
    public static function apply(string $locale): void
    {
        App::setLocale($locale);
        Number::useLocale($locale);
    }

    /**
     * Cookie that persists an explicit choice for one year (SameSite=Lax,
     * HttpOnly; `secure` follows config/session.php).
     */
    public static function cookie(string $locale): Cookie
    {
        return cookie(self::COOKIE, $locale, self::COOKIE_MINUTES, sameSite: 'lax');
    }
}
