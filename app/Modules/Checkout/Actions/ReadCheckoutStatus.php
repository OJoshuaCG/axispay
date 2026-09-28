<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Data\CheckoutStatus;
use App\Modules\Checkout\Enums\CheckoutPhase;
use App\Modules\Checkout\Enums\CheckoutState;
use App\Modules\Checkout\Services\CheckoutConnection;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\LinkReservation;
use App\Modules\Tenancy\Services\TenantAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * State of the payment page (plan 11.2, 11.5): read from our database
 * (ADR-017). While a payment is under way and its attempt has not changed
 * for a while, the attempt is re-read from the gateway (at most once per
 * interval, whoever polls): the page is not stuck when a webhook is late,
 * and a payer who comes back after 3D Secure sees the payment completed
 * (plan 11.4: "the backend may retrieve the payment to speed things up").
 * The interval grows with the attempt's idle time
 * (`status_sync_intervals_seconds`), so a 3D Secure step left open does not
 * keep asking the gateway. The webhook stays the source of truth.
 */
final readonly class ReadCheckoutStatus
{
    public function __construct(
        private SyncPaymentAttempt $sync,
        private Repository $cache,
        private TenantAccess $access,
        private CheckoutConnection $connection,
    ) {}

    /** The status, re-reading a payment under way from the gateway when due. */
    public function handle(PaymentLink $link): CheckoutStatus
    {
        $attempt = PaymentAttempt::query()->activeForLink($link->id)->first();

        if ($attempt !== null && $attempt->status->isInFlight() && ! $attempt->leaseHeld() && $this->due($attempt)) {
            try {
                // The status request has its own time budget (a separate payer request).
                $attempt = $this->sync->handle($attempt->id, SyncReason::Checkout, budget: CallBudget::forPayerRequest());
            } catch (Throwable $e) {
                Log::warning('The checkout status could not be re-read from the gateway.', ['payment_attempt_id' => $attempt->id, 'exception' => $e::class]);
            }

            $link->refresh();
        }

        $state = $this->state($link, $attempt);

        return new CheckoutStatus($state, $link->status === PaymentLinkStatus::Processing ? self::phase($attempt) : null, $state === CheckoutState::Paid ? $link->return_url : null);
    }

    /**
     * The page state of a link. `$attempt` is its active attempt when the
     * caller already read it (otherwise it is read when needed).
     */
    public function state(PaymentLink $link, ?PaymentAttempt $attempt = null): CheckoutState
    {
        return match ($link->status) {
            PaymentLinkStatus::Paid => CheckoutState::Paid,
            PaymentLinkStatus::Expired => CheckoutState::Expired,
            PaymentLinkStatus::Canceled => CheckoutState::Canceled,
            // An abandoned reservation (the confirmation died) offers the form again.
            PaymentLinkStatus::Processing => LinkReservation::isAbandoned($link, $attempt) && $this->access->collects($link->tenant_id) ? CheckoutState::Active : CheckoutState::Processing,
            // Plan 21.3: a closed tenant's links no longer take payments
            // (ADR-013: a suspended tenant keeps collecting).
            PaymentLinkStatus::Active => match (true) {
                ! $this->access->collects($link->tenant_id) => CheckoutState::Canceled,
                $link->isCheckoutBlocked() || $this->connection->chargeable() === null => CheckoutState::Unavailable,
                default => CheckoutState::Active,
            },
        };
    }

    private static function phase(?PaymentAttempt $attempt): CheckoutPhase
    {
        return $attempt?->status === PaymentAttemptStatus::RequiresCapture ? CheckoutPhase::Validating : CheckoutPhase::Processing;
    }

    private function due(PaymentAttempt $attempt): bool
    {
        $interval = self::interval($attempt);

        if ($attempt->updated_at !== null && $attempt->updated_at->greaterThan(CarbonImmutable::now()->subSeconds($interval))) {
            return false;
        }

        return $this->cache->add('checkout:status-sync:'.$attempt->id, 1, $interval);
    }

    /** Seconds between two re-reads: grows with the attempt's idle time. */
    private static function interval(PaymentAttempt $attempt): int
    {
        $intervals = array_values(array_filter(config()->array('axispay.checkout.status_sync_intervals_seconds'), is_int(...)));

        if ($intervals === []) {
            return max(1, config()->integer('axispay.checkout.status_sync_after_seconds'));
        }

        $idle = $attempt->updated_at !== null ? max(0, CarbonImmutable::now()->getTimestamp() - $attempt->updated_at->getTimestamp()) : 0;
        $step = intdiv($idle, max(1, config()->integer('axispay.checkout.status_sync_backoff_step_seconds')));

        return max(1, $intervals[min($step, count($intervals) - 1)]);
    }
}
