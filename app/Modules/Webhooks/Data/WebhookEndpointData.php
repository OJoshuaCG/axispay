<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

/**
 * Input of CreateWebhookEndpoint and UpdateWebhookEndpoint. `events` holds
 * event types of the catalog (WebhookEventType, without `ping`) or `*` for
 * every event. The mode is the panel's current one, never chosen here.
 */
final readonly class WebhookEndpointData
{
    /**
     * @param  list<string>  $events
     */
    public function __construct(
        public string $url,
        public ?string $description,
        public array $events,
    ) {}
}
