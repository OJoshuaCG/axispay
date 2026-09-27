<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use Illuminate\Support\Facades\Vite;

/**
 * The checkout's Content-Security-Policy (plan 11.8, DESIGN.md), built for
 * one nonce. Sources from Stripe's CSP guide (docs.stripe.com/security/guide,
 * "Stripe.js": js.stripe.com and *.js.stripe.com for scripts and frames,
 * hooks.stripe.com for 3D Secure frames, api.stripe.com for requests) and
 * Cloudflare Turnstile (challenges.cloudflare.com for its script and
 * frame). Google Maps (Stripe's Address Element) is not used. While the
 * Vite dev server runs (local only), its origin is added so assets load.
 */
final class CheckoutContentSecurityPolicy
{
    public function header(string $nonce): string
    {
        $dev = $this->devServerOrigins();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", 'https://js.stripe.com', 'https://*.js.stripe.com', 'https://challenges.cloudflare.com', ...$dev],
            'style-src' => ["'self'", "'nonce-{$nonce}'", ...$dev],
            'frame-src' => ['https://js.stripe.com', 'https://*.js.stripe.com', 'https://hooks.stripe.com', 'https://challenges.cloudflare.com'],
            'connect-src' => ["'self'", 'https://api.stripe.com', ...$dev, ...array_map(static fn (string $origin): string => preg_replace('/^http/', 'ws', $origin) ?? $origin, $dev)],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", ...$dev],
            'form-action' => ["'self'"],
            'base-uri' => ["'none'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'none'"],
        ];

        return implode('; ', array_map(
            static fn (string $name, array $sources): string => $name.' '.implode(' ', array_unique($sources)),
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * @return list<string>
     */
    private function devServerOrigins(): array
    {
        if (! app()->environment('local') || ! Vite::isRunningHot()) {
            return [];
        }

        $url = trim((string) file_get_contents(Vite::hotFile()));
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        return [$parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')];
    }
}
