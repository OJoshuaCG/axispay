<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Middleware;

use App\Modules\Checkout\Services\CheckoutLocale;
use App\Modules\PaymentLinks\Services\PaymentLinkLookup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The link's language for every answer of `/l/{token}` (plan 11.3), set
 * before the request limits run (bootstrap/app.php priority), so even a
 * "too many requests" answer speaks the link's language. The payer's own
 * choice (language switcher) still wins (CheckoutLocale).
 */
final readonly class ApplyCheckoutLocale
{
    public function __construct(private PaymentLinkLookup $lookup) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');
        $link = is_string($token) ? $this->lookup->forPublicToken($token) : null;

        if ($link !== null && is_string($link->locale)) {
            CheckoutLocale::apply($request, $link->locale);
        }

        return $next($request);
    }
}
