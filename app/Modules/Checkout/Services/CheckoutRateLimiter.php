<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Card-testing rate limits of the checkout (plan 11.7 rules 1 and 2,
 * ADR-0051), in the cache (short-lived); values in
 * `axispay.checkout.rate_limits`:
 *
 *  1. per link: `link_attempts` confirmations in `link_window_minutes`, then
 *     the link refuses confirmations for `link_block_minutes`;
 *  2. per client IP (the /64 network for IPv6): `ip_attempts` confirmations
 *     per `ip_window_minutes`, across links.
 *
 * Only confirmations that actually reach the gateway's confirm call count
 * (reserveConfirmation(): an atomic increment, compared after it, so a
 * parallel burst cannot overshoot; requests answered "in progress" never
 * count). Unrecognized tokens and gateway failures while reading the token
 * count per link and client, so they only pause that client; unrecognized
 * tokens also count per client network across links (`ip_bogus_attempts`),
 * so rotating links cannot hammer the gateway.
 */
final readonly class CheckoutRateLimiter
{
    public function __construct(private Repository $cache) {}

    /**
     * Whether a confirmation of `$link` from `$clientIp` may go on, WITHOUT
     * counting it (reserveConfirmation() counts, right before the gateway's
     * confirm call). Returns the minutes to wait, or null. Starts the link's
     * pause when its counter is full.
     */
    public function check(PaymentLink $link, ?string $clientIp): ?int
    {
        $pausedUntil = $this->cache->get(self::pausedKey($link));

        if (is_int($pausedUntil) && $pausedUntil > time()) {
            return self::minutes($pausedUntil - time());
        }

        $ipKey = self::ipKey($clientIp);

        if ($ipKey !== null && RateLimiter::tooManyAttempts($ipKey, self::limit('ip_attempts'))) {
            Log::notice('Checkout confirmations paused for a client IP.', ['payment_link_id' => $link->id]);

            return self::minutes(RateLimiter::availableIn($ipKey));
        }

        $ipBogusKey = self::ipBogusKey($clientIp);

        if ($ipBogusKey !== null && RateLimiter::tooManyAttempts($ipBogusKey, self::limit('ip_bogus_attempts'))) {
            Log::notice('Checkout confirmations paused for a client network sending unrecognized tokens.', ['payment_link_id' => $link->id]);

            return self::minutes(RateLimiter::availableIn($ipBogusKey));
        }

        // Tokens the gateway did not recognize count per link AND client, so
        // a stranger posting bogus tokens never pauses the link for others.
        $bogusKey = self::bogusKey($link, $clientIp);

        if (RateLimiter::tooManyAttempts($bogusKey, self::limit('link_attempts'))) {
            return self::minutes(RateLimiter::availableIn($bogusKey));
        }

        if (RateLimiter::tooManyAttempts(self::linkKey($link), self::limit('link_attempts'))) {
            return $this->pause($link);
        }

        return null;
    }

    /**
     * Plan 11.7 rules 1 and 2, atomically: counts one confirmation about to
     * reach the gateway, then compares. Over a limit, the count is taken
     * back, the link's pause starts (rule 1) and the minutes to wait are
     * returned; the confirmation must not go on.
     */
    public function reserveConfirmation(PaymentLink $link, ?string $clientIp): ?int
    {
        $linkKey = self::linkKey($link);
        $linkWindow = self::seconds('link_window_minutes');
        $ipKey = self::ipKey($clientIp);
        $ipWindow = self::seconds('ip_window_minutes');

        $linkHits = RateLimiter::increment($linkKey, $linkWindow);
        $ipHits = $ipKey !== null ? RateLimiter::increment($ipKey, $ipWindow) : 0;
        $linkOver = $linkHits > self::limit('link_attempts');
        $ipOver = $ipHits > self::limit('ip_attempts');

        if (! $linkOver && ! $ipOver) {
            return null;
        }

        RateLimiter::decrement($linkKey, $linkWindow);

        if ($ipKey !== null) {
            RateLimiter::decrement($ipKey, $ipWindow);
        }

        if ($linkOver) {
            return $this->pause($link);
        }

        Log::notice('Checkout confirmations paused for a client IP.', ['payment_link_id' => $link->id]);

        return $ipKey !== null ? self::minutes(RateLimiter::availableIn($ipKey)) : 1;
    }

    /**
     * Counts a confirmation token the gateway did not recognize: for this
     * link and client, and for the client's network across links.
     */
    public function countUnrecognizedToken(PaymentLink $link, ?string $clientIp): void
    {
        RateLimiter::hit(self::bogusKey($link, $clientIp), self::seconds('link_window_minutes'));

        if (($ipBogusKey = self::ipBogusKey($clientIp)) !== null) {
            RateLimiter::hit($ipBogusKey, self::seconds('ip_window_minutes'));
        }
    }

    /**
     * The gateway could not read the token (unavailable, rate limited):
     * counted like an unrecognized token for this link and client, so a
     * client cannot make the platform hammer the gateway without bound.
     */
    public function countGatewayFailure(PaymentLink $link, ?string $clientIp): void
    {
        RateLimiter::hit(self::bogusKey($link, $clientIp), self::seconds('link_window_minutes'));
    }

    /** Minutes left of the short pause of a link, if any (page load). */
    public function pausedMinutes(PaymentLink $link): ?int
    {
        $pausedUntil = $this->cache->get(self::pausedKey($link));

        return is_int($pausedUntil) && $pausedUntil > time() ? self::minutes($pausedUntil - time()) : null;
    }

    /**
     * The client's network for the per-IP limit: the address for IPv4, the
     * /64 prefix for IPv6 (one subscriber usually holds a whole /64, so
     * rotating addresses inside it must not escape the limit).
     */
    public static function clientNetwork(?string $clientIp): ?string
    {
        if ($clientIp === null || $clientIp === '') {
            return null;
        }

        if (filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($clientIp);

            return $packed !== false ? bin2hex(substr($packed, 0, 8)).'::/64' : $clientIp;
        }

        return $clientIp;
    }

    private function pause(PaymentLink $link): int
    {
        $seconds = self::seconds('link_block_minutes');
        $this->cache->put(self::pausedKey($link), time() + $seconds, $seconds);
        RateLimiter::clear(self::linkKey($link));
        Log::notice('Checkout confirmations paused for a payment link.', ['payment_link_id' => $link->id, 'minutes' => $seconds / 60]);

        return self::minutes($seconds);
    }

    private static function pausedKey(PaymentLink $link): string
    {
        return 'checkout:link-paused:'.$link->id;
    }

    private static function linkKey(PaymentLink $link): string
    {
        return 'checkout:link:'.$link->id;
    }

    private static function ipKey(?string $clientIp): ?string
    {
        $network = self::clientNetwork($clientIp);

        return $network !== null ? 'checkout:ip:'.hash('sha256', $network) : null;
    }

    private static function ipBogusKey(?string $clientIp): ?string
    {
        $network = self::clientNetwork($clientIp);

        return $network !== null ? 'checkout:bogus-ip:'.hash('sha256', $network) : null;
    }

    private static function bogusKey(PaymentLink $link, ?string $clientIp): string
    {
        return 'checkout:bogus-token:'.$link->id.':'.hash('sha256', (string) self::clientNetwork($clientIp));
    }

    private static function limit(string $key): int
    {
        return max(1, config()->integer('axispay.checkout.rate_limits.'.$key));
    }

    private static function seconds(string $minutesKey): int
    {
        return self::limit($minutesKey) * 60;
    }

    private static function minutes(int $seconds): int
    {
        return max(1, (int) ceil($seconds / 60));
    }
}
