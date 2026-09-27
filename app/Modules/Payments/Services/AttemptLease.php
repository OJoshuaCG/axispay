<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\PaymentAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Short lease on an attempt while one process talks to the gateway about it
 * (confirming, validating with the merchant, capturing). Another tab, a
 * webhook or the reconciliation finds it held and backs off, so the attempt
 * is never confirmed or completed twice in parallel. A crashed process frees
 * it after `axispay.checkout.confirmation_lease_seconds`.
 */
final class AttemptLease
{
    /** Takes the lease on an attempt the caller locked; false when someone else holds it. */
    public function acquireLocked(PaymentAttempt $locked): bool
    {
        if ($locked->leaseHeld()) {
            return false;
        }

        $locked->forceFill(['confirmation_lease_until' => CarbonImmutable::now()->addSeconds(max(5, config()->integer('axispay.checkout.confirmation_lease_seconds')))])->save();

        return true;
    }

    public function acquire(string $attemptId): bool
    {
        return DB::transaction(function () use ($attemptId): bool {
            $locked = PaymentAttempt::query()->lockForUpdate()->find($attemptId);

            return $locked !== null && ! $locked->status->isTerminal() && $this->acquireLocked($locked);
        });
    }

    public function release(string $attemptId): void
    {
        PaymentAttempt::query()->whereKey($attemptId)->update(['confirmation_lease_until' => null]);
    }
}
