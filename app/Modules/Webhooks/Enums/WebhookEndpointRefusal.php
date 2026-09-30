<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Why a webhook endpoint operation was refused (panel only; translated in
 * lang/{en,es}/webhooks.php `errors`).
 */
enum WebhookEndpointRefusal: string
{
    case TenantReadOnly = 'tenant_read_only';
    case TooManyEndpoints = 'too_many_endpoints';
    case NoEvents = 'no_events';
    case UnknownEvent = 'unknown_event';
    case DescriptionTooLong = 'description_too_long';
    case EndpointDisabled = 'endpoint_disabled';

    public function message(): string
    {
        return __('webhooks.errors.'.$this->value);
    }
}
