<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Why a merchant URL is refused by the SSRF protection (plan 15.7).
 * Translated in lang/{en,es}/webhooks.php `destination`.
 */
enum UnsafeDestinationReason: string
{
    case InvalidUrl = 'invalid_url';
    case TooLong = 'too_long';
    case SchemeNotAllowed = 'scheme_not_allowed';
    case CredentialsInUrl = 'credentials_in_url';
    case PortNotAllowed = 'port_not_allowed';
    case IpLiteralHost = 'ip_literal_host';
    case ForbiddenHost = 'forbidden_host';
    case UnresolvableHost = 'unresolvable_host';
    case ForbiddenAddress = 'forbidden_address';

    public function message(): string
    {
        return __('webhooks.destination.'.$this->value);
    }
}
