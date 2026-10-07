<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\ListPayments;
use App\Modules\Payments\Http\Presenters\PaymentPresenter;
use App\Modules\Payments\Http\Requests\ListPaymentsQuery;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `payment` endpoints of the public API (plan 10.6). HTTP only: parse, hand
 * to an action, present. Tenant and mode come from the API key
 * (AuthenticateApiKey); the `payments:read` scope and the rate limit are
 * route middleware (routes/api.php). Read only: nothing changes.
 */
final class PaymentController
{
    public function show(string $id): JsonResponse
    {
        // Wrong prefix, unknown ID, another tenant or another mode: all 404.
        $attempt = PaymentAttempt::query()->with(['link', 'fxQuote'])->find(PrefixedId::decode($id, ResourceType::Payment))
            ?? throw ApiException::of(ApiErrorCode::ResourceNotFound, 'No such payment.');

        return self::json(PaymentPresenter::toApi($attempt, self::linkOf($attempt)));
    }

    public function index(Request $request, ListPayments $list): JsonResponse
    {
        $page = $list->handle(ListPaymentsQuery::from($request));

        return self::json([
            'object' => 'list',
            'data' => array_map(static fn (PaymentAttempt $attempt): array => PaymentPresenter::toApi($attempt, self::linkOf($attempt)), $page->payments),
            'has_more' => $page->hasMore,
        ]);
    }

    private static function linkOf(PaymentAttempt $attempt): PaymentLink
    {
        return $attempt->link ?? throw ApiException::of(ApiErrorCode::ResourceNotFound, 'No such payment.');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body): JsonResponse
    {
        return new JsonResponse($body, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
