<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentAttemptFailure;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Card-testing protection of the checkout (plan 11.7, ADR-0051):
 *
 *  1. per link: `link_attempts` confirmations in `link_window_minutes`, then
 *     the link refuses confirmations for `link_block_minutes`;
 *  2. per client IP (the /64 network for IPv6): `ip_attempts` confirmations
 *     per `ip_window_minutes`, across links;
 *
 * Rules 1 and 2 only count confirmations that actually reach the gateway's
 * confirm call (reserveConfirmation(): an atomic increment, compared after
 * it, so a parallel burst cannot overshoot; requests answered "in progress"
 * never count). Unrecognized tokens and gateway failures while reading the
 * token count per link and client, so they only pause that client, and
 * unrecognized tokens also count per client network across links
 * (`ip_bogus_attempts`), so rotating links cannot hammer the gateway
 * (ADR-0051).
 *  3. Turnstile once the link, or the payer's session, has
 *     `turnstile_after_failures` declines;
 *  4. the long block (`long_block_declines` declines → `long_block_hours`)
 *     lives on the link (BlockCheckoutAfterDeclines) so the tenant can lift
 *     it; declines before the tenant lifted it no longer count.
 *
 * Counters 1-2 live in the cache (short-lived); 3-4 count the recorded
 * declines in the database.
 */
final readonly class CardTestingGuard
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
        $config = config()->array('axispay.checkout.rate_limits');
        $blockKey = 'checkout:link-paused:'.$link->id;
        $pausedUntil = $this->cache->get($blockKey);

        if (is_int($pausedUntil) && $pausedUntil > time()) {
            return self::minutes($pausedUntil - time());
        }

        $ipKey = self::ipKey($clientIp);

        if ($ipKey !== null && RateLimiter::tooManyAttempts($ipKey, self::int($config, 'ip_attempts', 10))) {
            Log::notice('Checkout confirmations paused for a client IP.', ['payment_link_id' => $link->id]);

            return self::minutes(RateLimiter::availableIn($ipKey));
        }

        $ipBogusKey = self::ipBogusKey($clientIp);

        if ($ipBogusKey !== null && RateLimiter::tooManyAttempts($ipBogusKey, self::int($config, 'ip_bogus_attempts', 20))) {
            Log::notice('Checkout confirmations paused for a client network sending unrecognized tokens.', ['payment_link_id' => $link->id]);

            return self::minutes(RateLimiter::availableIn($ipBogusKey));
        }

        // Tokens the gateway did not recognize count per link AND client, so
        // a stranger posting bogus tokens never pauses the link for others.
        $bogusKey = self::bogusKey($link, $clientIp);

        if (RateLimiter::tooManyAttempts($bogusKey, self::int($config, 'link_attempts', 5))) {
            return self::minutes(RateLimiter::availableIn($bogusKey));
        }

        $linkKey = 'checkout:link:'.$link->id;

        if (RateLimiter::tooManyAttempts($linkKey, self::int($config, 'link_attempts', 5))) {
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
        $config = config()->array('axispay.checkout.rate_limits');
        $linkKey = 'checkout:link:'.$link->id;
        $linkWindow = self::int($config, 'link_window_minutes', 15) * 60;
        $ipKey = self::ipKey($clientIp);
        $ipWindow = self::int($config, 'ip_window_minutes', 60) * 60;

        $linkHits = RateLimiter::increment($linkKey, $linkWindow);
        $ipHits = $ipKey !== null ? RateLimiter::increment($ipKey, $ipWindow) : 0;
        $linkOver = $linkHits > self::int($config, 'link_attempts', 5);
        $ipOver = $ipHits > self::int($config, 'ip_attempts', 10);

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
        $config = config()->array('axispay.checkout.rate_limits');

        RateLimiter::hit(self::bogusKey($link, $clientIp), self::int($config, 'link_window_minutes', 15) * 60);

        if (($ipBogusKey = self::ipBogusKey($clientIp)) !== null) {
            RateLimiter::hit($ipBogusKey, self::int($config, 'ip_window_minutes', 60) * 60);
        }
    }

    /**
     * The gateway could not read the token (unavailable, rate limited):
     * counted like an unrecognized token for this link and client, so a
     * client cannot make the platform hammer the gateway without bound.
     */
    public function countGatewayFailure(PaymentLink $link, ?string $clientIp): void
    {
        RateLimiter::hit(self::bogusKey($link, $clientIp), self::int(config()->array('axispay.checkout.rate_limits'), 'link_window_minutes', 15) * 60);
    }

    private function pause(PaymentLink $link): int
    {
        $seconds = self::int(config()->array('axispay.checkout.rate_limits'), 'link_block_minutes', 30) * 60;
        $this->cache->put('checkout:link-paused:'.$link->id, time() + $seconds, $seconds);
        RateLimiter::clear('checkout:link:'.$link->id);
        Log::notice('Checkout confirmations paused for a payment link.', ['payment_link_id' => $link->id, 'minutes' => $seconds / 60]);

        return self::minutes($seconds);
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

    /** Minutes left of the short pause of a link, if any (page load). */
    public function pausedMinutes(PaymentLink $link): ?int
    {
        $pausedUntil = $this->cache->get('checkout:link-paused:'.$link->id);

        return is_int($pausedUntil) && $pausedUntil > time() ? self::minutes($pausedUntil - time()) : null;
    }

    public function turnstileRequired(PaymentLink $link, int $sessionDeclines): bool
    {
        $threshold = max(1, config()->integer('axispay.checkout.turnstile_after_failures'));

        return $sessionDeclines >= $threshold || $this->declinesOf($link) >= $threshold;
    }

    /**
     * Declines recorded on the link's attempts in the last long-block window,
     * after the tenant last lifted a block.
     */
    public function declinesOf(PaymentLink $link): int
    {
        $since = CarbonImmutable::now()->subHours(max(1, config()->integer('axispay.checkout.long_block_hours')));

        if ($link->checkout_unblocked_at !== null && $link->checkout_unblocked_at->greaterThan($since)) {
            $since = $link->checkout_unblocked_at;
        }

        return PaymentAttemptFailure::query()
            ->whereIn('payment_attempt_id', PaymentAttempt::query()->select('id')->where('payment_link_id', $link->id))
            ->where('created_at', '>', $since->utc()->format('Y-m-d H:i:s.u'))
            ->count();
    }

    private static function minutes(int $seconds): int
    {
        return max(1, (int) ceil($seconds / 60));
    }

    /**
     * @param  array<mixed>  $config
     */
    private static function int(array $config, string $key, int $default): int
    {
        return is_int($config[$key] ?? null) ? $config[$key] : $default;
    }
}
