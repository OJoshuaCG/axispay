<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Exceptions;

use App\Modules\Shared\Contracts\UserFacingError;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use RuntimeException;

/**
 * A webhook endpoint operation refused (panel only; never an API answer).
 */
final class WebhookEndpointNotAllowedException extends RuntimeException implements UserFacingError
{
    public function __construct(public readonly WebhookEndpointRefusal $reason)
    {
        parent::__construct("Webhook endpoint operation not allowed: {$reason->value}.");
    }

    public function userMessage(): string
    {
        return $this->reason->message();
    }
}
