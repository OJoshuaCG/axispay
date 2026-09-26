<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Http\Middleware;

use App\Modules\ApiKeys\Data\IdempotentRequest;
use App\Modules\ApiKeys\Enums\IdempotencyRequirement;
use App\Modules\ApiKeys\Services\CurrentApiKey;
use App\Modules\ApiKeys\Services\CurrentIdempotentRequest;
use App\Modules\ApiKeys\Services\IdempotencyStore;
use App\Modules\ApiKeys\Services\RequestFingerprint;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `Idempotency-Key` on POST endpoints (plan 10.3). With
 * IdempotencyRequirement::Required the header is mandatory
 * (`400 idempotency_key_required`); otherwise it is optional but honored
 * when present. Once the request owns the key, CurrentIdempotentRequest
 * exposes the key and the body fingerprint to the action.
 */
final readonly class EnforceIdempotency
{
    public const string HEADER = 'Idempotency-Key';

    private const string PATTERN = '/^[A-Za-z0-9_\-:.]{1,255}$/D';

    public function __construct(
        private IdempotencyStore $store,
        private CurrentApiKey $current,
        private RequestFingerprint $fingerprint,
        private CurrentIdempotentRequest $idempotent,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $requirement = 'optional'): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $key = $request->headers->get(self::HEADER);

        if ($key === null || $key === '') {
            if (IdempotencyRequirement::from($requirement) === IdempotencyRequirement::Required) {
                throw ApiException::of(ApiErrorCode::IdempotencyKeyRequired, param: self::HEADER);
            }

            return $next($request);
        }

        if (preg_match(self::PATTERN, $key) !== 1) {
            throw ApiException::of(
                ApiErrorCode::ParameterInvalid,
                'The Idempotency-Key must be 1 to 255 characters from A-Z, a-z, 0-9, "_", "-", ":" and ".".',
                self::HEADER,
            );
        }

        $bodyHash = $this->fingerprint->of($request->getContent());

        $decision = $this->store->begin(new IdempotentRequest(
            apiKeyId: $this->current->getOrFail()->id,
            key: $key,
            method: $request->getMethod(),
            path: '/'.ltrim($request->path(), '/'),
            bodyHash: $bodyHash,
        ));

        if ($decision->replay) {
            return new Response((string) $decision->record->response_body, (int) $decision->record->response_status, [
                'Content-Type' => 'application/json',
                'Idempotent-Replayed' => 'true',
            ]);
        }

        $this->idempotent->set($key, $bodyHash);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->store->release($decision);

            throw $e;
        }

        // Plan 10.3: 5xx responses are not stored, so the client can retry.
        if ($response->getStatusCode() >= 500) {
            $this->store->release($decision);
        } else {
            $this->store->complete($decision, $response->getStatusCode(), (string) $response->getContent());
        }

        return $response;
    }
}
