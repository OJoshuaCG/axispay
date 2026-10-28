<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Whether the receiver of a webhook body must answer it (`delivery_mode` of
 * the body, ADR-0069): `sync` is the pre-payment validation, where the payer
 * is waiting for an `approve` or `reject`; `async` is every other event, where
 * any 2xx is enough and the answer is not read.
 */
enum WebhookDeliveryMode: string
{
    case Sync = 'sync';
    case Async = 'async';
}
