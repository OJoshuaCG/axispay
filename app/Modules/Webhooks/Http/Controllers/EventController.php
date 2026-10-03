<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Webhooks\Actions\ListEvents;
use App\Modules\Webhooks\Actions\RetrieveEvent;
use App\Modules\Webhooks\Http\Presenters\EventPresenter;
use App\Modules\Webhooks\Http\Requests\ListEventsQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `event` endpoints of the public API (plan 10.8). HTTP only: parse, hand to
 * an action, present. Tenant and mode come from the API key
 * (AuthenticateApiKey); the scope and the rate limit are route middleware
 * (routes/api.php). The body is the frozen webhook body (EventPresenter).
 */
final class EventController
{
    public function show(string $id, RetrieveEvent $retrieve): Response
    {
        $event = $retrieve->handle(PrefixedId::decode($id, ResourceType::Event))
            ?? throw ApiException::of(ApiErrorCode::ResourceNotFound, 'No such event.');

        return self::json(EventPresenter::one($event));
    }

    public function index(Request $request, ListEvents $list): Response
    {
        $page = $list->handle(ListEventsQuery::from($request));

        return self::json(EventPresenter::list($page->events, $page->hasMore));
    }

    private static function json(string $body): Response
    {
        return new Response($body, 200, ['Content-Type' => 'application/json']);
    }
}
