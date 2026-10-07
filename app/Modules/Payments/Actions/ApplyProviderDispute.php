<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Gateways\Data\ProviderDispute;
use App\Modules\PaymentLinks\Enums\DisputeStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\DisputeState;
use App\Modules\Payments\Models\Dispute;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptLocks;
use App\Modules\Payments\Services\DisputeSnapshot;
use App\Modules\Payments\Services\PaymentSnapshot;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Services\DomainEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE single place where a dispute, as the gateway reports it now, is applied
 * to our records (plan 16.2, 14.3, ADR-0066). With direct charges the
 * merchant answers it in the gateway's own dashboard: the platform only
 * records it and tells the integrator.
 *
 * In one transaction, link first and then attempt (rules.md rule 8):
 *
 *  1. the dispute is found by the gateway's ID, or created (`dispute.created`
 *     is recorded, also when it is first seen already closed);
 *  2. it moves to the gateway's state, only forwards: an open dispute (needs
 *     a response, under review) can close as won, lost or an inquiry closed
 *     without a chargeback, and a closed one never moves again; closing
 *     records `dispute.closed`;
 *  3. the link's `dispute_status` summarizes its payment's disputes: open
 *     while any is open, else lost if any was lost, else won.
 *
 * Idempotent: the same gateway state twice changes and announces nothing.
 */
final readonly class ApplyProviderDispute
{
    public function __construct(private DomainEventRecorder $events) {}

    /**
     * @return Dispute|null the dispute as it is now; null when it was not applied
     */
    public function handle(string $attemptId, ProviderDispute $provider): ?Dispute
    {
        $linkId = AttemptLocks::linkIdOf($attemptId);

        return DB::transaction(function () use ($linkId, $attemptId, $provider): ?Dispute {
            [$link, $attempt] = AttemptLocks::lockLinkThenAttemptOrFail($linkId, $attemptId);

            if ($provider->providerPaymentId !== null && $provider->providerPaymentId !== $attempt->provider_payment_id) {
                Log::warning('A gateway dispute names another payment than the attempt it was read for; not applied.', ['payment_attempt_id' => $attempt->id]);

                return null;
            }

            $dispute = Dispute::query()->where('provider_dispute_id', $provider->providerDisputeId)->lockForUpdate()->first();

            if ($dispute !== null && $dispute->payment_attempt_id !== $attempt->id) {
                Log::warning('A gateway dispute belongs to another payment than the attempt it was read for; not applied.', ['payment_attempt_id' => $attempt->id]);

                return null;
            }

            $target = DisputeState::from($provider->status->value);
            $isNew = $dispute === null;
            $closes = false;

            if ($dispute === null) {
                $dispute = $this->create($attempt, $provider, $target);
            } else {
                $closes = $dispute->status->isOpen() && ! $target->isOpen();

                if ($dispute->status->isOpen()) {
                    $this->move($dispute, $target);
                }
            }

            $dispute->save();
            $this->summarize($link, $attempt);

            $facts = ['dispute' => DisputeSnapshot::of($dispute), 'payment' => PaymentSnapshot::of($attempt, $link)];

            if ($isNew) {
                $this->events->record(DomainEventType::DisputeCreated, 'dispute', $dispute->id, $facts);
            }

            if (($isNew && ! $target->isOpen()) || $closes) {
                $this->events->record(DomainEventType::DisputeClosed, 'dispute', $dispute->id, $facts);
            }

            return $dispute;
        });
    }

    private function create(PaymentAttempt $attempt, ProviderDispute $provider, DisputeState $state): Dispute
    {
        $dispute = new Dispute;
        $dispute->forceFill([
            'payment_attempt_id' => $attempt->id,
            'provider_dispute_id' => $provider->providerDisputeId,
            'amount_minor' => $provider->amountMinor,
            'currency' => $attempt->currency,
            'reason' => $provider->reason !== null ? substr($provider->reason, 0, Dispute::REASON_MAX) : null,
            'status' => $state,
            'evidence_due_by' => $provider->evidenceDueBy !== null ? CarbonImmutable::createFromTimestampUTC($provider->evidenceDueBy) : null,
            'opened_at' => $provider->createdAt !== null ? CarbonImmutable::createFromTimestampUTC($provider->createdAt) : CarbonImmutable::now(),
            'closed_at' => $state->isOpen() ? null : CarbonImmutable::now(),
        ]);

        return $dispute;
    }

    private function move(Dispute $dispute, DisputeState $target): void
    {
        $dispute->status = $target;
        $dispute->closed_at = $target->isOpen() ? null : CarbonImmutable::now();
    }

    /** The link's summary, from every dispute of its payment, under the caller's locks. */
    private function summarize(PaymentLink $link, PaymentAttempt $attempt): void
    {
        $summaries = Dispute::query()
            ->where('payment_attempt_id', $attempt->id)
            ->get()
            ->map(static fn (Dispute $dispute): DisputeStatus => $dispute->status->summary());

        $summary = match (true) {
            $summaries->contains(DisputeStatus::Open) => DisputeStatus::Open,
            $summaries->contains(DisputeStatus::Lost) => DisputeStatus::Lost,
            $summaries->contains(DisputeStatus::Won) => DisputeStatus::Won,
            default => DisputeStatus::None,
        };

        $link->forceFill(['dispute_status' => $summary]);

        if ($link->isDirty()) {
            $link->save();
        }
    }
}
