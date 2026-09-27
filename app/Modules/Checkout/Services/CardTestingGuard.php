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
 *  2. per client IP: `ip_attempts` confirmations per `ip_window_minutes`,
 *     across links;
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
     * Counts one confirmation of `$link` from `$clientIp`. Returns the
     * minutes the payer must wait, or null when the confirmation may go on.
     */
    public function throttle(PaymentLink $link, ?string $clientIp): ?int
    {
        $config = config()->array('axispay.checkout.rate_limits');
        $blockKey = 'checkout:link-paused:'.$link->id;
        $pausedUntil = $this->cache->get($blockKey);

        if (is_int($pausedUntil) && $pausedUntil > time()) {
            return self::minutes($pausedUntil - time());
        }

        $ipKey = 'checkout:ip:'.hash('sha256', (string) $clientIp);

        if ($clientIp !== null && RateLimiter::tooManyAttempts($ipKey, self::int($config, 'ip_attempts', 10))) {
            Log::notice('Checkout confirmations paused for a client IP.', ['payment_link_id' => $link->id]);

            return self::minutes(RateLimiter::availableIn($ipKey));
        }

        $linkKey = 'checkout:link:'.$link->id;

        if (RateLimiter::tooManyAttempts($linkKey, self::int($config, 'link_attempts', 5))) {
            $seconds = self::int($config, 'link_block_minutes', 30) * 60;
            $this->cache->put($blockKey, time() + $seconds, $seconds);
            RateLimiter::clear($linkKey);
            Log::notice('Checkout confirmations paused for a payment link.', ['payment_link_id' => $link->id, 'minutes' => $seconds / 60]);

            return self::minutes($seconds);
        }

        RateLimiter::hit($linkKey, self::int($config, 'link_window_minutes', 15) * 60);

        if ($clientIp !== null) {
            RateLimiter::hit($ipKey, self::int($config, 'ip_window_minutes', 60) * 60);
        }

        return null;
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
