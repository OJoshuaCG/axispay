<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Actions\ReadCheckoutStatus;
use App\Modules\Checkout\Actions\RecordCheckoutOpening;
use App\Modules\Checkout\Http\CheckoutResponses;
use App\Modules\Checkout\Http\CheckoutSession;
use App\Modules\Checkout\Services\CheckoutLinkResolver;
use App\Modules\Checkout\Services\CheckoutLocale;
use App\Modules\Checkout\Services\CheckoutPageBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /l/{token} and GET /l/{token}/complete (plan 11.5). Presentation only:
 * the link is resolved through the tenant-safe resolver, the opening is
 * recorded by its action, and the page is built by CheckoutPageBuilder.
 * `complete` is where the payer lands after paying or 3D Secure: it re-reads
 * the payment first (plan 11.4) and never marks anything paid by itself.
 */
final readonly class CheckoutPageController
{
    public function __construct(
        private CheckoutLinkResolver $resolver,
        private CheckoutPageBuilder $pages,
        private CheckoutResponses $responses,
    ) {}

    public function show(Request $request, string $token, RecordCheckoutOpening $openings): Response|JsonResponse
    {
        $link = $this->resolver->resolve($token);

        if ($link === null) {
            return $this->responses->notFound();
        }

        CheckoutLocale::apply($request, $link->locale);
        $openings->handle($link, $request->userAgent());

        return new Response(view('checkout.page', [
            'page' => $this->pages->build($link, sessionDeclines: CheckoutSession::declines($request, $link->id)),
        ])->render());
    }

    public function complete(Request $request, string $token, ReadCheckoutStatus $status): Response|JsonResponse
    {
        $link = $this->resolver->resolve($token);

        if ($link === null) {
            return $this->responses->notFound();
        }

        CheckoutLocale::apply($request, $link->locale);
        // Re-reads the payment first; the link is refreshed there when it did.
        $current = $status->handle($link);

        return new Response(view('checkout.page', [
            'page' => $this->pages->build($link, $current->phase, CheckoutSession::paidHere($request, $link->id), CheckoutSession::declines($request, $link->id)),
        ])->render());
    }
}
