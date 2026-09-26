<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Outcome of the daily api_key health check (plan 12.3.3).
 */
enum HealthCheckStatus: string
{
    case Ok = 'ok';
    case AuthenticationFailed = 'authentication_failed';
    case Unavailable = 'unavailable';
}
