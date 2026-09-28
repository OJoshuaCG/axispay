<?php

declare(strict_types=1);

namespace App\Modules\Branding\Http\Controllers;

use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Models\PlatformLogo;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the platform logo same-origin on the admin, app and pay hosts
 * (ADR-0053), so the checkout's `img-src 'self'` holds. The URL carries the
 * version: an old or unknown version is a 404, and a matching one can be
 * cached for a year (a change always gets a new version). No session, no
 * cookies: the route runs without the session middleware.
 */
final class PlatformLogoController
{
    public function __invoke(string $variant, string $version): Response
    {
        $logoVariant = LogoVariant::tryFrom($variant);

        $logo = $logoVariant === null ? null : PlatformLogo::query()
            ->where('variant', $logoVariant->value)
            ->where('version', $version)
            ->first();

        abort_if($logo === null, 404);

        return new Response($logo->content, 200, [
            'Content-Type' => $logo->mime_type,
            'Content-Length' => (string) strlen($logo->content),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
