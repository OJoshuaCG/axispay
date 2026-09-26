<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Http\Middleware;

use App\Modules\ApiKeys\Services\CurrentApiKey;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limit per API key (plan 10.1, ADR-0048): a fixed one-minute window
 * of `axispay.api.rate_limit_per_minute.{live|test}` requests. Every response
 * carries `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset`
 * (seconds); over the limit the answer is `429 rate_limited` with
 * `Retry-After`.
 */
final readonly class ThrottleApiKey
{
    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private CurrentApiKey $current,
        private RateLimiter $limiter,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->current->getOrFail();
        $limit = max(1, config()->integer('axispay.api.rate_limit_per_minute.'.($key->livemode ? 'live' : 'test')));
        $bucket = "axispay:api_rate:{$key->id}";

        // One atomic increment decides: concurrent requests can never all
        // pass a check made before any of them counted.
        $count = $this->limiter->increment($bucket, self::WINDOW_SECONDS);

        if ($count > $limit) {
            $retryAfter = max(1, $this->limiter->availableIn($bucket));

            throw new ApiException(ApiErrorCode::RateLimited, headers: [
                'Retry-After' => (string) $retryAfter,
                ...$this->headers($limit, 0, $retryAfter),
            ]);
        }

        $response = $next($request);

        $response->headers->add($this->headers($limit, $limit - $count, max(0, $this->limiter->availableIn($bucket))));

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headers(int $limit, int $remaining, int $reset): array
    {
        return [
            'RateLimit-Limit' => (string) $limit,
            'RateLimit-Remaining' => (string) max(0, $remaining),
            'RateLimit-Reset' => (string) $reset,
        ];
    }
}
