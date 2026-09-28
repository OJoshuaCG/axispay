<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Database\Transactions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * THE lock order of payments (rules.md rule 8): the link first, then the
 * attempt, everywhere, so two processes working on the same payment never
 * deadlock on each other. Only inside the caller's transaction.
 *
 * The attempt's link never changes, so the caller reads it BEFORE opening
 * the transaction (linkIdOf()): a plain read inside the transaction would
 * open a read snapshot, and MariaDB then refuses to lock a row another
 * process changed since ("record has changed since last read").
 */
final class AttemptLocks
{
    /** The attempt's link (immutable). Read it outside the locking transaction. */
    public static function linkIdOf(string $attemptId): ?string
    {
        $linkId = PaymentAttempt::query()->whereKey($attemptId)->value('payment_link_id');

        return is_string($linkId) ? $linkId : null;
    }

    /**
     * @return array{0: PaymentLink|null, 1: PaymentAttempt|null} the locked link and attempt (null when missing)
     */
    public static function lockLinkThenAttempt(?string $linkId, string $attemptId): array
    {
        if (! Transactions::open()) {
            throw new LogicException('Row locks only live inside a transaction.');
        }

        $link = $linkId !== null ? PaymentLink::query()->whereKey($linkId)->lockForUpdate()->first() : null;
        $attempt = PaymentAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();

        if ($attempt !== null && $attempt->payment_link_id !== $linkId) {
            throw new LogicException('The attempt does not belong to the given link.');
        }

        return [$link, $attempt];
    }

    /**
     * The same, failing when either row is missing.
     *
     * @return array{0: PaymentLink, 1: PaymentAttempt}
     *
     * @throws ModelNotFoundException<PaymentLink|PaymentAttempt>
     */
    public static function lockLinkThenAttemptOrFail(?string $linkId, string $attemptId): array
    {
        [$link, $attempt] = self::lockLinkThenAttempt($linkId, $attemptId);

        if ($attempt === null) {
            throw (new ModelNotFoundException)->setModel(PaymentAttempt::class, [$attemptId]);
        }

        if ($link === null) {
            throw (new ModelNotFoundException)->setModel(PaymentLink::class, [$attempt->payment_link_id]);
        }

        return [$link, $attempt];
    }
}
