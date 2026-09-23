<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Errors;

/**
 * Error types of the public API (plan section 10.4).
 */
enum ApiErrorType: string
{
    case InvalidRequest = 'invalid_request_error';
    case Authentication = 'authentication_error';
    case Permission = 'permission_error';
    case RateLimit = 'rate_limit_error';
    case Api = 'api_error';
}
