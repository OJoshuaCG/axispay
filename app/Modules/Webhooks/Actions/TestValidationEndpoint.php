<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Database\Transactions;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Webhooks\Data\ValidationTestResult;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Services\PrePaymentValidationClient;
use App\Modules\Webhooks\Services\ValidationCallRecorder;
use App\Modules\Webhooks\Services\ValidationPayload;
use App\Modules\Webhooks\Services\ValidationResponseParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * "Test validation" (plan 15.8.1): sends a signed example payload with
 * `test: true`, NOW and synchronously (at most the 5-second budget), and
 * returns what the panel shows: HTTP status, latency, decision, whether the
 * format is valid (exactly the rules of a real call), the problems found and
 * a sanitized excerpt of the answer. Logged in `validation_calls` as a test;
 * it never changes the endpoint's failure count. `webhooks:manage`; never
 * call it inside a transaction.
 */
final readonly class TestValidationEndpoint
{
    public function __construct(
        private TenantAccess $access,
        private PrePaymentValidationClient $client,
        private ValidationResponseParser $parser,
        private ValidationCallRecorder $recorder,
        private AuditLogger $audit,
    ) {}

    public function handle(User $actor, ValidationEndpoint $endpoint): ValidationTestResult
    {
        if (Transactions::open()) {
            throw new LogicException('A validation test is sent outside any database transaction.');
        }

        if (! $this->access->panelWritable($actor->tenant_id)) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::TenantReadOnly);
        }

        Gate::forUser($actor)->authorize('test', $endpoint);

        $call = new ValidationCall;
        $call->forceFill([
            'id' => Ulid::generate(),
            'tenant_id' => $endpoint->tenant_id,
            'livemode' => $endpoint->livemode,
            'validation_endpoint_id' => $endpoint->id,
            'is_test' => true,
        ]);
        $payload = ValidationPayload::sample($call->prefixedId(), $endpoint->livemode, CarbonImmutable::now());

        DB::transaction(function () use ($actor, $endpoint, $call, $payload): void {
            $call->forceFill(['request_payload' => $payload])->save();

            $this->audit->record(AuditAction::ValidationEndpointTestSent, $endpoint, [
                'host' => $endpoint->host(),
                'livemode' => $endpoint->livemode,
            ], actor: Actor::user($actor->id));
        });

        $result = $this->client->send($endpoint, $call->prefixedId(), ValidationPayload::encode($payload));
        $parsed = $result->transportFailure === null ? $this->parser->parse($result) : null;
        $failure = $result->transportFailure ?? match (true) {
            $parsed !== null && $parsed->valid() => null,
            $result->status !== 200 => ValidationFailureKind::HttpError,
            default => ValidationFailureKind::InvalidResponse,
        };

        $this->recorder->record($call, $endpoint, $result, $parsed, $failure, $failure !== null ? $endpoint->failure_policy : null);

        return new ValidationTestResult(
            call: $call,
            httpStatus: $result->status,
            latencyMs: $result->durationMs,
            decision: $parsed?->valid() === true ? $parsed->decision : null,
            formatValid: $failure === null,
            errors: $parsed?->errors() ?? [],
            warnings: $parsed?->warnings() ?? [],
            responseExcerpt: $call->response_body_excerpt,
            failureKind: $failure,
        );
    }
}
