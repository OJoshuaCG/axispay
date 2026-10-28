<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Models\WebhookEndpoint;

/**
 * The webhook endpoint of the current tenant and mode that answers the
 * pre-payment validation, if any (ADR-0069: at most one, the one subscribed to
 * `payment.pre_validation`). Used by the payment link creation to accept
 * `pre_payment_validation` and to apply `validation_enabled_by_default`.
 */
final class ValidationEndpoints
{
    public function current(): ?WebhookEndpoint
    {
        return WebhookEndpoint::query()->validator()->first();
    }
}
