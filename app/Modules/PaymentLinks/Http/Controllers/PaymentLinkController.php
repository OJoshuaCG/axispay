<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Http\Controllers;

use App\Modules\ApiKeys\Services\CurrentApiKey;
use App\Modules\ApiKeys\Services\CurrentIdempotentRequest;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Actions\CreatePaymentLink;
use App\Modules\PaymentLinks\Actions\ListPaymentLinks;
use App\Modules\PaymentLinks\Data\CreationContext;
use App\Modules\PaymentLinks\Enums\CreatedVia;
use App\Modules\PaymentLinks\Http\Presenters\PaymentLinkPresenter;
use App\Modules\PaymentLinks\Http\Requests\ListPaymentLinksQuery;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\CancelPaymentLinkInputParser;
use App\Modules\PaymentLinks\Services\PaymentLinkInputParser;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Http\JsonBody;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `payment_link` endpoints of the public API (plan 10.5). HTTP only: parse,
 * hand to an action, present. Tenant and mode come from the API key
 * (AuthenticateApiKey); scopes, rate limit and idempotency are route
 * middleware (routes/api.php).
 */
final class PaymentLinkController
{
    public function store(Request $request, PaymentLinkInputParser $parser, CreatePaymentLink $create, CurrentApiKey $apiKey, CurrentIdempotentRequest $idempotent): JsonResponse
    {
        $link = $create->handle($parser->parse(JsonBody::of($request)), new CreationContext(
            via: CreatedVia::Api,
            actor: $apiKey->actor(),
            idempotencyKey: $idempotent->key(),
            requestHash: $idempotent->bodyHash(),
        ));

        return self::json(PaymentLinkPresenter::toApi($link), 201);
    }

    public function show(string $id): JsonResponse
    {
        return self::json(PaymentLinkPresenter::toApi(self::find($id)));
    }

    public function index(Request $request, ListPaymentLinks $list): JsonResponse
    {
        $page = $list->handle(ListPaymentLinksQuery::from($request));

        return self::json([
            'object' => 'list',
            'data' => array_map(PaymentLinkPresenter::toApi(...), $page->links),
            'has_more' => $page->hasMore,
        ]);
    }

    public function cancel(Request $request, string $id, CancelPaymentLinkInputParser $parser, CancelPaymentLink $cancel, CurrentApiKey $apiKey): JsonResponse
    {
        $link = self::find($id);

        return self::json(PaymentLinkPresenter::toApi($cancel->handle($link, $parser->parse(JsonBody::of($request)), $apiKey->actor())));
    }

    /** Wrong prefix, unknown ID, another tenant or another mode: all 404. */
    private static function find(string $id): PaymentLink
    {
        return PaymentLink::query()->find(PrefixedId::decode($id, ResourceType::PaymentLink))
            ?? throw ApiException::of(ApiErrorCode::ResourceNotFound, 'No such payment link.');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
