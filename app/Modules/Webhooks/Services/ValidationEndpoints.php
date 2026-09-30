<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Models\ValidationEndpoint;

/**
 * The validation endpoint of the current tenant and mode, if any (plan
 * 15.8.1: at most one). Used by the payment link creation to accept
 * `pre_payment_validation` and to apply `enabled_by_default`.
 */
final class ValidationEndpoints
{
    public function current(): ?ValidationEndpoint
    {
        return ValidationEndpoint::query()->first();
    }
}
