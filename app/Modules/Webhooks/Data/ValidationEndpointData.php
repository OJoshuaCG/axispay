<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Enums\ValidationFailurePolicy;

/**
 * Input of ConfigureValidationEndpoint (plan 15.8.1). The mode is the
 * panel's current one, never chosen here.
 */
final readonly class ValidationEndpointData
{
    public function __construct(
        public string $url,
        public bool $enabledByDefault = false,
        public ValidationFailurePolicy $failurePolicy = ValidationFailurePolicy::FailClosed,
    ) {}
}
