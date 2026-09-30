<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * What happened to a validation endpoint, for the e-mail to the owners and
 * the users with `webhooks:manage` (plan 15.8.1, 15.8.5, 17.3).
 */
enum ValidationEndpointChange: string
{
    case Configured = 'configured';
    case Updated = 'updated';
    case SecretRotated = 'secret_rotated';
    case Removed = 'removed';
    case Failing = 'failing';
}
