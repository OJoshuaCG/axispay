<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\PaymentAttemptFailure;
use Carbon\CarbonImmutable;

/**
 * Declines of a link, counted from the recorded declines (plan 11.7 rules 3
 * and 4): Turnstile once the link, or the payer's session, has
 * `turnstile_after_failures` declines; the long block
 * (BlockCheckoutAfterDeclines) after `long_block_declines` in
 * `long_block_hours`. Declines before the tenant last lifted a block no
 * longer count.
 */
final readonly class LinkDeclineCounter
{
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
}
