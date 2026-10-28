<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;

/**
 * Normalizes the event subscription of an endpoint (plan 7.6
 * `enabled_events`): `["*"]` for every asynchronous event, or the chosen types
 * of the catalog in catalog order. `ping` cannot be subscribed to. The
 * wildcard never covers `payment.pre_validation` (ADR-0069); it is kept next
 * to it (`["*", "payment.pre_validation"]`) when the merchant chose it.
 */
final class EnabledEvents
{
    private function __construct() {}

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    public static function normalize(array $events): array
    {
        $events = array_values(array_unique(array_map('trim', $events)));

        if ($events === []) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::NoEvents);
        }

        if (in_array(WebhookEventType::WILDCARD, $events, true)) {
            return in_array(WebhookEventType::PaymentPreValidation->value, $events, true)
                ? [WebhookEventType::WILDCARD, WebhookEventType::PaymentPreValidation->value]
                : [WebhookEventType::WILDCARD];
        }

        $catalog = WebhookEventType::subscribableValues();

        foreach ($events as $event) {
            if (! in_array($event, $catalog, true)) {
                throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::UnknownEvent);
            }
        }

        return array_values(array_intersect($catalog, $events));
    }
}
