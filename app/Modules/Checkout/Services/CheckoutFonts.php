<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use Illuminate\Support\Facades\Vite;

/**
 * Self-hosted font files of the build (vite.config.js fonts), for what the
 * checkout needs beyond `@fonts`:
 *
 *  - Mukta sources for Stripe Elements (`fonts: [{family, src, weight}]`),
 *    so the card fields inside Stripe's iframe use the page's typeface. The
 *    iframe is on Stripe's origin: the woff2 files must be served with CORS
 *    (docker/app/nginx.conf). Never a font CDN;
 *  - the Geist Mono 600 file, preloaded on the checkout only (the total).
 *
 * Reads public/build/fonts-manifest.json; while the dev server runs (hot)
 * or without a build it returns nothing and the system fallbacks apply.
 */
final class CheckoutFonts
{
    /** @var array<mixed>|null */
    private ?array $manifest = null;

    /**
     * Mukta weights the card form's Appearance uses (resources/js/checkout/
     * appearance.js: normal 400, medium and bold 500). No other weight is
     * sent to Stripe, so the iframe downloads nothing it does not render.
     */
    public const array STRIPE_WEIGHTS = [400, 500];

    /**
     * @return list<array{family: string, src: string, weight: string}>
     */
    public function stripeFonts(): array
    {
        $fonts = [];

        foreach (self::STRIPE_WEIGHTS as $weight) {
            $url = $this->fileUrl('mukta', $weight);

            if ($url !== null) {
                $fonts[] = ['family' => 'Mukta', 'src' => 'url('.$url.')', 'weight' => (string) $weight];
            }
        }

        return $fonts;
    }

    public function numericPreloadUrl(): ?string
    {
        return $this->fileUrl('geist-mono', 600);
    }

    private function fileUrl(string $family, int $weight): ?string
    {
        $style = $this->manifest()['style'] ?? null;
        $families = is_array($style) ? ($style['familyStyles'] ?? null) : null;
        $styles = is_array($families) ? ($families[$family] ?? null) : null;

        if (! is_string($styles)) {
            return null;
        }

        $pattern = '/font-weight:\s*'.$weight.';[^}]*?src:\s*url\("([^"]+\.woff2)"\)/s';

        return preg_match($pattern, $styles, $match) === 1 ? url($match[1]) : null;
    }

    /**
     * @return array<mixed>
     */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = public_path('build/fonts-manifest.json');

        if (Vite::isRunningHot() || ! is_file($path)) {
            return $this->manifest = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->manifest = is_array($decoded) ? $decoded : [];
    }
}
