<?php

declare(strict_types=1);

namespace App\Modules\Branding\Http\Controllers;

use App\Modules\Branding\Enums\FaviconSize;
use App\Modules\Branding\Models\PlatformFavicon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the platform favicon same-origin on the admin, app and pay hosts
 * (ADR-0053), like PlatformLogoController: versioned URL (an old or unknown
 * version is a 404), cached for a year, no session and no cookies.
 */
final class PlatformFaviconController
{
    public function __invoke(string $size, string $version): Response
    {
        $faviconSize = FaviconSize::tryFrom((int) $size);

        $favicon = $faviconSize === null ? null : PlatformFavicon::query()
            ->where('size', $faviconSize->value)
            ->where('version', $version)
            ->first();

        abort_if($favicon === null, 404);

        return new Response($favicon->content, 200, [
            'Content-Type' => $favicon->mime_type,
            'Content-Length' => (string) strlen($favicon->content),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
