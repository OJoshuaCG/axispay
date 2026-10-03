<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Models\WebhookEvent;

/**
 * One page of events, newest first, and whether more exist in the direction
 * that was paged.
 */
final readonly class EventPage
{
    /**
     * @param  list<WebhookEvent>  $events
     */
    public function __construct(
        public array $events,
        public bool $hasMore,
    ) {}
}
