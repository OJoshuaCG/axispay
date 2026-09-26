<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Gateway events that e-mail the tenant's owners and `gateway:manage`
 * holders (plan 17.3, 22).
 */
enum ConnectionNotice: string
{
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Restricted = 'restricted';
    case InvalidCredentials = 'invalid_credentials';
    case ExcessivePermissions = 'excessive_permissions';
}
