<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Services;

use App\Modules\ApiKeys\Data\IdempotencyDecision;
use App\Modules\ApiKeys\Data\IdempotentRequest;
use App\Modules\ApiKeys\Models\IdempotencyRecord;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\SecureToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API idempotency (plan 7.8, 10.3), in the current tenant context.
 *
 * begin() claims the key by INSERTING its row (autocommit, outside any
 * business transaction), so the unique index `(tenant_id, livemode,
 * idempotency_key)` decides which of several concurrent requests runs: the
 * winner gets `proceed`; the others find the row and get
 *
 *  - `409 idempotency_request_in_progress` while it is still running;
 *  - a replay of the stored response once it finished (same body);
 *  - `422 idempotency_key_reused` when the method, path or body differ.
 *
 * Responses are stored when the request finishes (complete); a 5xx or an
 * exception releases the key instead, so the client can retry. A row whose
 * lock expired without a response (crashed worker) is taken over; an expired
 * row (24 hours) counts as a fresh key.
 *
 * Every claim carries its own random lock token. complete() and release()
 * only touch the row while it still belongs to that token and has no
 * response, so a request that lost the key to a takeover can neither
 * overwrite nor delete the new owner's row; that is logged and reported to
 * the caller.
 */
final class IdempotencyStore
{
    public function begin(IdempotentRequest $request): IdempotencyDecision
    {
        $token = self::newToken();

        try {
            $record = new IdempotencyRecord;
            $record->forceFill([...$this->freshAttributes($request), 'lock_token' => $token])->save();

            return IdempotencyDecision::proceed($record, $token);
        } catch (UniqueConstraintViolationException) {
            // Somebody else holds (or held) this key: decide below.
        }

        return DB::transaction(function () use ($request, $token): IdempotencyDecision {
            $existing = IdempotencyRecord::query()
                ->where('idempotency_key', $request->key)
                ->lockForUpdate()
                ->first();

            // Deleted between our insert and this read (released after a 5xx):
            // the client retries.
            if ($existing === null) {
                throw ApiException::of(ApiErrorCode::IdempotencyRequestInProgress);
            }

            if ($existing->isExpired()) {
                $existing->forceFill([...$this->freshAttributes($request), 'response_status' => null, 'response_body' => null, 'lock_token' => $token])->save();

                return IdempotencyDecision::proceed($existing, $token);
            }

            if ($existing->request_method !== $request->method
                || $existing->request_path !== $request->path
                || ! hash_equals($existing->request_hash, $request->bodyHash)) {
                throw ApiException::of(ApiErrorCode::IdempotencyKeyReused);
            }

            if ($existing->hasResponse()) {
                return IdempotencyDecision::replay($existing);
            }

            if ($existing->isLocked()) {
                throw ApiException::of(ApiErrorCode::IdempotencyRequestInProgress);
            }

            $existing->forceFill(['locked_until' => $this->lockUntil(), 'api_key_id' => $request->apiKeyId, 'lock_token' => $token])->save();

            return IdempotencyDecision::proceed($existing, $token);
        });
    }

    /**
     * Stores the answer. False (and a warning) when the key was taken over
     * meanwhile: the answer was sent to this client but is not stored.
     */
    public function complete(IdempotencyDecision $decision, int $status, string $body): bool
    {
        $stored = $this->owned($decision)->update([
            'response_status' => $status,
            'response_body' => $body,
            'locked_until' => null,
            'lock_token' => null,
        ]) === 1;

        if (! $stored) {
            Log::warning('Idempotency key lost before its answer could be stored.', ['idempotency_record_id' => $decision->record->id, 'status' => $status]);
        }

        return $stored;
    }

    /**
     * Frees the key for a retry. False (and a warning) when the key was
     * taken over meanwhile: the new owner's row is left alone.
     */
    public function release(IdempotencyDecision $decision): bool
    {
        $released = $this->owned($decision)->delete() === 1;

        if (! $released) {
            Log::warning('Idempotency key lost before it could be released.', ['idempotency_record_id' => $decision->record->id]);
        }

        return $released;
    }

    /**
     * @return Builder<IdempotencyRecord>
     */
    private function owned(IdempotencyDecision $decision): Builder
    {
        return IdempotencyRecord::query()
            ->whereKey($decision->record->id)
            ->whereNull('response_status')
            ->where('lock_token', (string) $decision->lockToken);
    }

    private static function newToken(): string
    {
        return SecureToken::hex(16);
    }

    /**
     * @return array<string, mixed>
     */
    private function freshAttributes(IdempotentRequest $request): array
    {
        return [
            'api_key_id' => $request->apiKeyId,
            'idempotency_key' => $request->key,
            'request_method' => $request->method,
            'request_path' => $request->path,
            'request_hash' => $request->bodyHash,
            'locked_until' => $this->lockUntil(),
            'expires_at' => CarbonImmutable::now()->addHours(config()->integer('axispay.api.idempotency.ttl_hours')),
        ];
    }

    private function lockUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds(config()->integer('axispay.api.idempotency.lock_seconds'));
    }
}
