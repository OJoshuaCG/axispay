<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Data\ParsedValidationResponse;
use App\Modules\Webhooks\Data\ValidationHttpResult;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Enums\ValidationEndpointChange;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\ValidationFinalDecision;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes the result of a validation call once the merchant answered (or
 * failed), in one short transaction that starts only AFTER the HTTP call
 * (rules.md rule 7b):
 *
 *  - the `validation_calls` row: outcome, failure kind, policy applied,
 *    final decision, reason, sanitized message and excerpt, latency;
 *  - the endpoint's health (real calls only, never tests): a valid answer
 *    (approve or reject) resets the failures in a row; a failure adds one,
 *    and from `alert_after_failures` on the users with `webhooks:manage`
 *    are e-mailed, at most once per `alert_interval_minutes` (plan 15.8.5).
 *    Validation is never switched off by itself.
 */
final readonly class ValidationCallRecorder
{
    public function __construct(
        private ResponseExcerpt $excerpts,
        private ValidationEndpointNotifier $notifier,
    ) {}

    public function record(
        ValidationCall $call,
        ?ValidationEndpoint $endpoint,
        ?ValidationHttpResult $result,
        ?ParsedValidationResponse $parsed,
        ?ValidationFailureKind $failure,
        ?ValidationFailurePolicy $policy,
    ): ValidationCall {
        $outcome = match (true) {
            $failure !== null => ValidationCallOutcome::Failed,
            $parsed?->approved() === true => ValidationCallOutcome::Approved,
            default => ValidationCallOutcome::Rejected,
        };

        $finalDecision = match (true) {
            $call->is_test => null,
            $outcome === ValidationCallOutcome::Approved => ValidationFinalDecision::Charge,
            $outcome === ValidationCallOutcome::Failed && $policy?->charges() === true => ValidationFinalDecision::Charge,
            default => ValidationFinalDecision::Block,
        };

        $alert = DB::transaction(function () use ($call, $endpoint, $result, $parsed, $failure, $policy, $outcome, $finalDecision): ?ValidationEndpoint {
            $call->forceFill([
                'outcome' => $outcome,
                'failure_kind' => $failure,
                'policy_applied' => $failure !== null ? $policy : null,
                'final_decision' => $finalDecision,
                'reason_code' => $failure === null ? $parsed?->reasonCode : null,
                'payer_message' => $failure === null ? $parsed?->payerMessage : null,
                'cancel_link' => $failure === null && $parsed?->decision === 'reject' && $parsed->cancelLink,
                'connection_retried' => $result !== null && $result->retried,
                'response_status' => $result?->status,
                'response_body_excerpt' => $result !== null ? $this->excerpts->of($result->body, config()->integer('axispay.pre_payment_validation.response_excerpt_bytes')) : null,
                'duration_ms' => $result?->durationMs,
            ])->save();

            if ($call->is_test || $endpoint === null) {
                return null;
            }

            return $this->updateHealth($endpoint->id, $failure === null);
        });

        if ($alert !== null) {
            $this->notifier->notify($alert, ValidationEndpointChange::Failing);
        }

        return $call;
    }

    /** Returns the endpoint when its managers must be e-mailed now. */
    private function updateHealth(string $endpointId, bool $success): ?ValidationEndpoint
    {
        $locked = ValidationEndpoint::query()->lockForUpdate()->find($endpointId);

        if ($locked === null) {
            return null; // removed during the call
        }

        $now = CarbonImmutable::now();

        if ($success) {
            $locked->forceFill(['consecutive_failures' => 0, 'last_success_at' => $now])->save();

            return null;
        }

        $locked->forceFill(['consecutive_failures' => $locked->consecutive_failures + 1, 'last_failure_at' => $now]);
        $interval = max(1, config()->integer('axispay.pre_payment_validation.alert_interval_minutes'));
        $due = $locked->isFailing() && ($locked->last_alerted_at === null || $locked->last_alerted_at->lessThanOrEqualTo($now->subMinutes($interval)));

        if ($due) {
            $locked->forceFill(['last_alerted_at' => $now]);
        }

        $locked->save();

        return $due ? $locked : null;
    }
}
