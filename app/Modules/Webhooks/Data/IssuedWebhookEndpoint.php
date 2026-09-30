<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Models\WebhookEndpoint;
use SensitiveParameter;

/**
 * An endpoint and its signing secret in plaintext, returned ONCE by
 * CreateWebhookEndpoint and RotateWebhookEndpointSecret (plan 15.1). The
 * panel shows it and never keeps it in its state.
 */
final readonly class IssuedWebhookEndpoint
{
    public function __construct(
        public WebhookEndpoint $endpoint,
        #[SensitiveParameter]
        public string $secret,
    ) {}
}
