<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Http\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a request ID to every request (global middleware).
 *
 * A client-supplied `X-Request-Id` is reused only when it matches a safe
 * charset and length (so it cannot inject into logs or headers); otherwise a
 * new `req_<ULID>` is generated. The ID is added to the log context and
 * returned in the `Request-Id` response header.
 */
final class AssignRequestId
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(RequestId::INCOMING_HEADER);
        $requestId = RequestId::isSafe($incoming) ? (string) $incoming : RequestId::generate();

        Context::add(RequestId::CONTEXT_KEY, $requestId);

        $response = $next($request);
        $response->headers->set(RequestId::RESPONSE_HEADER, $requestId);

        return $response;
    }
}
