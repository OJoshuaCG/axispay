<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Why a pre-payment validation call failed (plan 7.6, 15.8.5). Every one of
 * them applies the endpoint's failure policy.
 */
enum ValidationFailureKind: string
{
    case Timeout = 'timeout';
    case ConnectionError = 'connection_error';
    case TlsError = 'tls_error';
    /** Any status other than 200, including 3xx (redirects are never followed). */
    case HttpError = 'http_error';
    case InvalidResponse = 'invalid_response';
    case BlockedDestination = 'blocked_destination';
    /** The link asks for validation but its mode has no endpoint any more (ADR-0058). */
    case EndpointMissing = 'endpoint_missing';

    public function label(): string
    {
        return __('webhooks.validation.failure_kind.'.$this->value);
    }
}
