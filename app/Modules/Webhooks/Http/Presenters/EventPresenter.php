<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Presenters;

use App\Modules\Webhooks\Models\WebhookEvent;

/**
 * The `event` of the public API (plan 10.8) is the body of the webhook,
 * byte for byte: the frozen JSON stored when the event was created is
 * returned as is, never decoded and encoded again, so it is identical to
 * what was (or will be) sent and signed. Lists embed those same bytes.
 */
final class EventPresenter
{
    private function __construct() {}

    public static function one(WebhookEvent $event): string
    {
        return $event->body();
    }

    /**
     * @param  list<WebhookEvent>  $events
     */
    public static function list(array $events, bool $hasMore): string
    {
        return '{"object":"list","data":['
            .implode(',', array_map(self::one(...), $events))
            .'],"has_more":'.($hasMore ? 'true' : 'false').'}';
    }
}
