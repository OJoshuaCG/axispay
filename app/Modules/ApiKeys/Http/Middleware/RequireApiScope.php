<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Http\Middleware;

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\ApiKeys\Services\CurrentApiKey;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.scope:links:create`: the authenticated key must hold the scope
 * (plan 10.2), otherwise `403 insufficient_scope`.
 */
final readonly class RequireApiScope
{
    public function __construct(private CurrentApiKey $current) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $required = ApiScope::from($scope);

        if (! $this->current->getOrFail()->hasScope($required)) {
            throw ApiException::of(
                ApiErrorCode::InsufficientScope,
                "The API key does not have the '{$required->value}' scope required for this request.",
            );
        }

        return $next($request);
    }
}
