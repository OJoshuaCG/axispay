<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Services\PaymentLinkStateMachine;
use App\Modules\Payments\Jobs\CloseAttemptOfClosedLinkJob;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Payments\Services\AttemptLocks;
use Illuminate\Support\Facades\DB;

/**
 * The end of a confirmation (ADR-0051): the link was reserved (`processing`)
 * when the attempt was claimed; if no payment is under way now (the
 * confirmation failed, was refused or never reached the gateway), the link
 * becomes payable again, or expired past its expiry (plan 9.1), and the
 * caller's lease is released. A payment under way keeps the link
 * processing until its outcome. If the link closed meanwhile, the attempt's
 * waiting payment is canceled once the lease is free.
 */
final readonly class ReleaseLinkAfterAttempt
{
    public function __construct(
        private PaymentLinkStateMachine $links,
        private AttemptLease $lease,
    ) {}

    public function handle(string $attemptId, string $leaseToken): void
    {
        $linkId = AttemptLocks::linkIdOf($attemptId);

        $closed = DB::transaction(function () use ($linkId, $attemptId): bool {
            [$link, $attempt] = AttemptLocks::lockLinkThenAttempt($linkId, $attemptId);

            if ($link === null || $attempt === null) {
                return false;
            }

            if ($link->status === PaymentLinkStatus::Processing && ! $attempt->status->isInFlight() && ! $attempt->status->isTerminal()) {
                $this->links->resumeAfterAttempt($link);
            }

            // The link closed (expired) while this confirmation held the
            // attempt: its waiting payment is canceled now that it is free.
            return in_array($link->status, [PaymentLinkStatus::Expired, PaymentLinkStatus::Canceled], true) && ! $attempt->status->isTerminal();
        });

        $this->lease->release($attemptId, $leaseToken);

        if ($closed) {
            CloseAttemptOfClosedLinkJob::dispatch($attemptId);
        }
    }
}
