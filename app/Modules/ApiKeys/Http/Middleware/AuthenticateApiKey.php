<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Http\Middleware;

use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Services\ApiKeyAuthenticator;
use App\Modules\ApiKeys\Services\CurrentApiKey;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API surface tenant resolution (plan 6.3, 10.2): `Authorization: Bearer
 * axp_{mode}_…` → key by hash → TenantContext with the key's tenant and
 * mode. A missing, unknown, revoked or expired key, or one whose prefix names
 * the other mode, is `401 invalid_api_key` with the same message.
 *
 * Failed authentications are limited per client IP
 * (`axispay.api.failed_auth_per_minute`): once over the limit, every request
 * from that IP answers `429 rate_limited` until the minute passes, so keys
 * cannot be guessed at speed. Successful requests do not count.
 *
 * What the tenant may do comes from TenantAccess (plan 10.2, 21.3); this
 * middleware only maps it to HTTP: read-only access answers anything but
 * GET/HEAD with `403 tenant_suspended`.
 *
 * `last_used_at` / `last_used_ip` are written at most once per key per
 * interval, not on every request.
 */
final readonly class AuthenticateApiKey
{
    public function __construct(
        private ApiKeyAuthenticator $authenticator,
        private TenantContext $context,
        private CurrentApiKey $current,
        private Cache $cache,
        private RateLimiter $limiter,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $failures = 'axispay:api_auth_failures:'.($request->ip() ?? 'unknown');
        $maxFailures = max(1, config()->integer('axispay.api.failed_auth_per_minute'));

        // Reading the counter needs no lock; every failure below is counted
        // with one atomic increment.
        if ($this->limiter->attempts($failures) >= $maxFailures) {
            $retryAfter = max(1, $this->limiter->availableIn($failures));

            throw new ApiException(ApiErrorCode::RateLimited, 'Too many failed authentication attempts. Retry later.', headers: ['Retry-After' => (string) $retryAfter]);
        }

        $token = $request->bearerToken();
        $authenticated = is_string($token) && $token !== '' ? $this->authenticator->authenticate($token) : null;

        if ($authenticated === null) {
            $this->limiter->increment($failures, 60);

            throw new ApiException(
                ApiErrorCode::InvalidApiKey,
                'Invalid API key provided. Send it as "Authorization: Bearer axp_test_..." or "axp_live_...".',
                headers: ['WWW-Authenticate' => 'Bearer'],
            );
        }

        if (! $authenticated->access->allowsMethod($request->getMethod())) {
            throw ApiException::of(ApiErrorCode::TenantSuspended, 'The account is closed: the API is read-only until access ends.');
        }

        $key = $authenticated->key;
        $this->context->set($key->tenant_id, $key->livemode);
        $this->current->set($key);
        $this->touch($key, $request);

        return $next($request);
    }

    private function touch(ApiKey $key, Request $request): void
    {
        $interval = config()->integer('axispay.api.last_used_interval_seconds');

        if (! $this->cache->add("axispay:api_key_used:{$key->id}", true, $interval)) {
            return;
        }

        ApiKey::query()->whereKey($key->id)->update([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ]);
    }
}
