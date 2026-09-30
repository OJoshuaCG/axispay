<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Why a delivery attempt failed (plan 7.6 `webhook_deliveries.error`).
 * Never an exception message: they can carry hosts, addresses or bodies.
 */
enum WebhookDeliveryError: string
{
    /** The receiver answered, but not with a 2xx (3xx included: redirects are not followed). */
    case HttpStatus = 'http_status';
    case Timeout = 'timeout';
    case DnsError = 'dns_error';
    case TlsError = 'tls_error';
    case ConnectionError = 'connection_error';
    case ResponseTooLarge = 'response_too_large';
    /** Refused by the SSRF protection (plan 15.7): never retried. */
    case BlockedDestination = 'blocked_destination';
    /** The endpoint was disabled or deleted before the attempt was sent. */
    case EndpointDisabled = 'endpoint_disabled';
    case InternalError = 'internal_error';

    /** Whether a later attempt can succeed (plan 15.7 rule 7). */
    public function isRetriable(): bool
    {
        return ! in_array($this, [self::BlockedDestination, self::EndpointDisabled], true);
    }

    public function label(): string
    {
        return __('webhooks.delivery_error.'.$this->value);
    }

    /** What the merchant can do about it, in one sentence (the test result dialog). */
    public function nextStep(): string
    {
        return __('webhooks.test_result.next_step.'.$this->value);
    }
}
