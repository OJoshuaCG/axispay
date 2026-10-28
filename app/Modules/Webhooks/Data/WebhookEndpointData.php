<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Enums\ValidationFailurePolicy;

/**
 * Input of CreateWebhookEndpoint and UpdateWebhookEndpoint. `events` holds
 * event types of the catalog (WebhookEventType, without `ping`) or `*` for
 * every asynchronous event; `payment.pre_validation` is chosen explicitly
 * (ADR-0069). The mode is the panel's current one, never chosen here.
 *
 * The validation settings only matter when `payment.pre_validation` is among
 * the events; they are stored either way so unchecking and re-checking the
 * event does not lose them.
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
        public ValidationFailurePolicy $validationFailurePolicy = ValidationFailurePolicy::FailClosed,
        public bool $validationEnabledByDefault = false,
    ) {}
}
