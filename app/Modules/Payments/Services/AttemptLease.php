<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Ids\SecureToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Short lease on an attempt while one process talks to the gateway about it
 * (confirming, validating with the merchant, capturing, voiding). Another
 * tab, a webhook or the reconciliation finds it held and backs off, so the
 * attempt is never worked on twice in parallel. Each lease has a token: only
 * its holder releases it. A crashed process frees it after
 * `axispay.checkout.confirmation_lease_seconds`.
 */
final class AttemptLease
{
    /**
     * Takes the lease on an attempt the caller locked (saving it; a new
     * attempt is created with its lease). Returns the lease token, or null
     * when someone else holds it.
     */
    public function acquireLocked(PaymentAttempt $locked): ?string
    {
        if ($locked->leaseHeld()) {
            return null;
        }

        $token = SecureToken::hex(16);
        $locked->forceFill([
            'confirmation_lease_until' => CarbonImmutable::now()->addSeconds(max(5, config()->integer('axispay.checkout.confirmation_lease_seconds'))),
            'confirmation_lease_token' => $token,
        ])->save();

        return $token;
    }

    /** Locks the attempt and takes its lease; null when held or final. */
    public function acquire(string $attemptId): ?string
    {
        return DB::transaction(function () use ($attemptId): ?string {
            $locked = PaymentAttempt::query()->lockForUpdate()->find($attemptId);

            return $locked !== null && ! $locked->status->isTerminal() ? $this->acquireLocked($locked) : null;
        });
    }

    /**
     * Renews the lease for another full period, before each gateway call, so
     * a slow call never runs on an expired lease. False when `$token` lost
     * the lease meanwhile: the caller must stop.
     */
    public function extend(string $attemptId, string $token): bool
    {
        $now = CarbonImmutable::now();
        $held = PaymentAttempt::query()
            ->whereKey($attemptId)
            ->where('confirmation_lease_token', $token)
            ->where('confirmation_lease_until', '>', $now->utc()->format('Y-m-d H:i:s.u'));

        // Affected rows are not a reliable answer (an unchanged value counts
        // as none in MariaDB): renew, then check the lease is still ours.
        (clone $held)->update(['confirmation_lease_until' => $now->addSeconds(max(5, config()->integer('axispay.checkout.confirmation_lease_seconds')))]);

        return $held->exists();
    }

    /** Releases the lease only if `$token` still holds it. */
    public function release(string $attemptId, string $token): void
    {
        PaymentAttempt::query()
            ->whereKey($attemptId)
            ->where('confirmation_lease_token', $token)
            ->update(['confirmation_lease_until' => null, 'confirmation_lease_token' => null]);
    }

    /** Whether `$token` is the current lease of the attempt (read under the caller's lock). */
    public static function holds(PaymentAttempt $attempt, ?string $token): bool
    {
        return $token !== null && $attempt->leaseHeld() && hash_equals((string) $attempt->confirmation_lease_token, $token);
    }
}
