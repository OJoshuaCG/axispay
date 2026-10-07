<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\ApiKeys\Services\CurrentApiKey;
use App\Modules\ApiKeys\Services\CurrentIdempotentRequest;
use App\Modules\Payments\Actions\ListRefunds;
use App\Modules\Payments\Actions\RefundPayment;
use App\Modules\Payments\Data\RefundCreation;
use App\Modules\Payments\Enums\RefundOrigin;
use App\Modules\Payments\Http\Requests\ListRefundsQuery;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundInputParser;
use App\Modules\Payments\Services\RefundSnapshot;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Http\JsonBody;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `refund` endpoints of the public API (plan 10.7). HTTP only: parse, hand to
 * an action, present. Tenant and mode come from the API key
 * (AuthenticateApiKey); scopes (`refunds:create`, `refunds:read`), the rate
 * limit and the mandatory Idempotency-Key are route middleware
 * (routes/api.php).
 */
final class RefundController
{
    public function store(Request $request, RefundInputParser $parser, RefundPayment $refund, CurrentApiKey $apiKey, CurrentIdempotentRequest $idempotent): JsonResponse
    {
        $created = $refund->handle($parser->parse(JsonBody::of($request)), new RefundCreation(
            origin: RefundOrigin::Api,
            actor: $apiKey->actor(),
            idempotencyKey: $idempotent->key(),
            requestHash: $idempotent->bodyHash(),
        ));

        return self::json(RefundSnapshot::of($created), 201);
    }

    public function show(string $id): JsonResponse
    {
        // Wrong prefix, unknown ID, another tenant or another mode: all 404.
        $refund = Refund::query()->find(PrefixedId::decode($id, ResourceType::Refund))
            ?? throw ApiException::of(ApiErrorCode::ResourceNotFound, 'No such refund.');

        return self::json(RefundSnapshot::of($refund));
    }

    public function index(Request $request, ListRefunds $list): JsonResponse
    {
        $page = $list->handle(ListRefundsQuery::from($request));

        return self::json([
            'object' => 'list',
            'data' => array_map(RefundSnapshot::of(...), $page->refunds),
            'has_more' => $page->hasMore,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
