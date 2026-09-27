<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Actions\ReadCheckoutStatus;
use App\Modules\Checkout\Http\CheckoutResponses;
use App\Modules\Checkout\Services\CheckoutLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * GET /l/{token}/status (plan 11.5): the minimal JSON the page polls
 * (`state`, `phase`). Never payment or payer details.
 */
final readonly class CheckoutStatusController
{
    public function __invoke(string $token, CheckoutLinkResolver $resolver, ReadCheckoutStatus $status, CheckoutResponses $responses): JsonResponse|Response
    {
        $link = $resolver->resolve($token);

        if ($link === null) {
            return $responses->notFound(json: true);
        }

        $current = $status->handle($link);

        return new JsonResponse(array_filter(['state' => $current->state->value, 'phase' => $current->phase]));
    }
}
