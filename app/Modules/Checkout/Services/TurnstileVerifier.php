<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Throwable;

/**
 * Server-side check of a Cloudflare Turnstile token (plan 11.7 rule 3),
 * always before the gateway is called. POST to Cloudflare's siteverify
 * endpoint with the secret, the token and the payer's IP; the token is valid
 * once and for 5 minutes (Cloudflare docs). Any failure (missing token,
 * rejection, timeout, missing secret) answers false: fail closed.
 *
 * Besides `success`, the answer must name the pay host as `hostname` and
 * `checkout` as `action` (set by the page's widget), as Cloudflare
 * recommends, so a token solved on another site or for another purpose is
 * refused. Cloudflare's test keys answer with a dummy hostname and no action
 * (flagged `result_with_testing_key`); that answer is accepted outside
 * production only.
 */
final class TurnstileVerifier
{
    public const string ACTION = 'checkout';

    public function siteKey(): ?string
    {
        $key = config('services.turnstile.site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function verify(#[SensitiveParameter] ?string $token, ?string $clientIp): bool
    {
        $secret = config('services.turnstile.secret_key');

        if ($token === null || $token === '' || strlen($token) > 2048) {
            return false;
        }

        if (! is_string($secret) || $secret === '') {
            Log::error('Turnstile is required but TURNSTILE_SECRET_KEY is not configured.');

            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(max(1, config()->integer('services.turnstile.timeout_seconds')))
                ->post(config()->string('services.turnstile.verify_url'), array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $clientIp,
                ]));
        } catch (Throwable $e) {
            Log::warning('Turnstile verification could not be completed.', ['exception' => $e::class]);

            return false;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            return false;
        }

        if ($response->json('metadata.result_with_testing_key') === true && ! app()->environment('production')) {
            return true;
        }

        $valid = $response->json('hostname') === config()->string('axispay.surfaces.pay') && $response->json('action') === self::ACTION;

        if (! $valid) {
            Log::warning('A Turnstile token was solved for another host or action.');
        }

        return $valid;
    }
}
