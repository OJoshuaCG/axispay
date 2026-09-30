<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * What happened to an endpoint, for the e-mail to the owners and the users
 * with `webhooks:manage` (plan 17.3, 22).
 */
enum WebhookEndpointChange: string
{
    case Created = 'created';
    case Updated = 'updated';
    case SecretRotated = 'secret_rotated';
    case DisabledByFailures = 'disabled_by_failures';
    case Deleted = 'deleted';
}
