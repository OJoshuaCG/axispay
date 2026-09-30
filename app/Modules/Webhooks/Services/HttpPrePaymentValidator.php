<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\NoPrePaymentValidation;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Webhooks\Data\ParsedValidationResponse;
use App\Modules\Webhooks\Data\ValidationHttpResult;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * ADR-0050 step 4 (plan 15.8): asks the merchant, with a signed synchronous
 * call, whether an AUTHORIZED payment may be captured.
 *
 *  - a link created without `pre_payment_validation` is not validated
 *    (NoPrePaymentValidation: straight to capture);
 *  - otherwise the call is logged in `validation_calls` (its ID is the
 *    `webhook-id`), sent (PrePaymentValidationClient: 5 s in total, one
 *    retry only when the connection could not be opened) and its answer read
 *    (ValidationResponseParser);
 *  - `approve` → capture; `reject` → void, with the merchant's message and
 *    `cancel_link`; any failure (timeout, connection, TLS, HTTP other than
 *    200, invalid answer, blocked destination, no endpoint any more) applies
 *    the endpoint's policy: `fail_closed` voids, `fail_open` captures and
 *    is recorded as such.
 *
 * Rules.md rule 7b: it refuses to run inside a transaction or under a row
 * lock (LogicException), and CaptureAuthorizedPayment re-locks and
 * re-verifies the link and the attempt after it.
 */
final readonly class HttpPrePaymentValidator implements PrePaymentValidator
{
    public function __construct(
        private NoPrePaymentValidation $none,
        private PrePaymentValidationClient $client,
        private ValidationResponseParser $parser,
        private ValidationCallRecorder $recorder,
    ) {}

    public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
    {
        if (Transactions::open()) {
            throw new LogicException('The pre-payment validation is never called inside a database transaction or under a row lock (rules.md rule 7b).');
        }

        if (! $link->pre_payment_validation) {
            return $this->none->decide($link, $attempt);
        }

        $endpoint = ValidationEndpoint::query()
            ->where('tenant_id', $link->tenant_id)
            ->where('livemode', $link->livemode)
            ->first();

        [$call, $body] = $this->open($link, $attempt, $endpoint);

        if ($endpoint === null) {
            // The endpoint was removed after the link was created: the link
            // still promises a validation, so nothing is charged (ADR-0058).
            return $this->failed($call, null, null, ValidationFailureKind::EndpointMissing, ValidationFailurePolicy::FailClosed);
        }

        $result = $this->client->send($endpoint, $call->prefixedId(), $body);

        if ($result->transportFailure !== null) {
            return $this->failed($call, $endpoint, $result, $result->transportFailure, $endpoint->failure_policy);
        }

        $parsed = $this->parser->parse($result);

        if (! $parsed->valid()) {
            $kind = $result->status !== 200 ? ValidationFailureKind::HttpError : ValidationFailureKind::InvalidResponse;

            return $this->failed($call, $endpoint, $result, $kind, $endpoint->failure_policy, $parsed);
        }

        $this->recorder->record($call, $endpoint, $result, $parsed, null, null);

        return $parsed->approved()
            ? PrePaymentDecision::approve()
            : PrePaymentDecision::reject($parsed->payerMessage, $parsed->cancelLink);
    }

    /**
     * The log row, written (auto-committed, no transaction) before the call
     * so its ID can be the `webhook-id`. `attempt_number` counts the
     * validations of the link: a payer who retries after a decline is
     * validated again (plan 15.8.2). Returns the row and the exact body.
     *
     * @return array{ValidationCall, string}
     */
    private function open(PaymentLink $link, PaymentAttempt $attempt, ?ValidationEndpoint $endpoint): array
    {
        $id = Ulid::generate();
        $attemptNumber = ValidationCall::query()->where('payment_link_id', $link->id)->where('is_test', false)->count() + 1;

        $call = new ValidationCall;
        $call->forceFill([
            'id' => $id,
            'tenant_id' => $link->tenant_id,
            'livemode' => $link->livemode,
            'validation_endpoint_id' => $endpoint?->id,
            'payment_link_id' => $link->id,
            'payment_attempt_id' => $attempt->id,
            'attempt_number' => $attemptNumber,
            'is_test' => false,
        ]);
        $payload = ValidationPayload::forAttempt($call->prefixedId(), $link, $attempt, $attemptNumber, CarbonImmutable::now());
        $call->forceFill(['request_payload' => $payload])->save();

        return [$call, ValidationPayload::encode($payload)];
    }

    private function failed(
        ValidationCall $call,
        ?ValidationEndpoint $endpoint,
        ?ValidationHttpResult $result,
        ValidationFailureKind $kind,
        ValidationFailurePolicy $policy,
        ?ParsedValidationResponse $parsed = null,
    ): PrePaymentDecision {
        $this->recorder->record($call, $endpoint, $result, $parsed, $kind, $policy);

        // Identifiers and the kind only: never the URL, the body or the secret.
        Log::warning('A pre-payment validation failed; the failure policy applies.', [
            'validation_call_id' => $call->id,
            'payment_attempt_id' => $call->payment_attempt_id,
            'failure_kind' => $kind->value,
            'policy' => $policy->value,
            'response_status' => $result?->status,
        ]);

        return $policy->charges() ? PrePaymentDecision::failedOpen() : PrePaymentDecision::failedClosed();
    }
}
