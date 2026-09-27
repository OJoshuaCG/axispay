<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Data\CheckoutStatus;
use App\Modules\Checkout\Enums\CheckoutState;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * State of the payment page (plan 11.2, 11.5): read from our database
 * (ADR-017). While a payment is under way and its attempt has not changed
 * for `axispay.checkout.status_sync_after_seconds`, the attempt is re-read
 * from the gateway (at most once per interval, whoever polls): the page is
 * not stuck when a webhook is late, and a payer who comes back after 3D
 * Secure sees the payment completed (plan 11.4: "the backend may retrieve
 * the payment to speed things up"). The webhook stays the source of truth.
 */
final readonly class ReadCheckoutStatus
{
    public function __construct(
        private SyncPaymentAttempt $sync,
        private Repository $cache,
    ) {}

    public function handle(PaymentLink $link, bool $sync = true): CheckoutStatus
    {
        $attempt = PaymentAttempt::query()
            ->where('payment_link_id', $link->id)
            ->whereIn('status', PaymentAttemptStatus::activeValues())
            ->first();

        if ($sync && $attempt !== null && $attempt->status->isInFlight() && ! $attempt->leaseHeld() && $this->due($attempt)) {
            try {
                $attempt = $this->sync->handle($attempt->id, SyncReason::Checkout);
            } catch (Throwable $e) {
                Log::warning('The checkout status could not be re-read from the gateway.', ['payment_attempt_id' => $attempt->id, 'exception' => $e::class]);
            }

            $link->refresh();
        }

        return new CheckoutStatus(self::state($link), $link->status === PaymentLinkStatus::Processing ? self::phase($attempt) : null);
    }

    public static function state(PaymentLink $link): CheckoutState
    {
        return match ($link->status) {
            PaymentLinkStatus::Paid => CheckoutState::Paid,
            PaymentLinkStatus::Expired => CheckoutState::Expired,
            PaymentLinkStatus::Canceled => CheckoutState::Canceled,
            PaymentLinkStatus::Processing => CheckoutState::Processing,
            PaymentLinkStatus::Active => $link->isCheckoutBlocked() || ! self::canCharge() ? CheckoutState::Unavailable : CheckoutState::Active,
        };
    }

    private static function canCharge(): bool
    {
        $connection = GatewayConnection::query()->current()->first();

        return $connection !== null && $connection->status->canCharge() && $connection->charges_enabled;
    }

    private static function phase(?PaymentAttempt $attempt): string
    {
        return match ($attempt?->status) {
            PaymentAttemptStatus::RequiresCapture => 'validating',
            default => 'processing',
        };
    }

    private function due(PaymentAttempt $attempt): bool
    {
        $after = max(1, config()->integer('axispay.checkout.status_sync_after_seconds'));

        if ($attempt->updated_at !== null && $attempt->updated_at->greaterThan(now()->subSeconds($after))) {
            return false;
        }

        return $this->cache->add('checkout:status-sync:'.$attempt->id, 1, $after);
    }
}
