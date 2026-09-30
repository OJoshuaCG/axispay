<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Why a delivery attempt exists. Only automatic attempts follow the retry
 * schedule and count towards the endpoint's continuous failures (plan 15.6);
 * a manual resend or a test is a single attempt shown to the user.
 */
enum WebhookDeliveryTrigger: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Test = 'test';

    public function label(): string
    {
        return __('webhooks.delivery_trigger.'.$this->value);
    }
}
