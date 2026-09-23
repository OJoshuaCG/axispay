<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

/**
 * Dispatched synchronously every time code enters the platform context
 * (TenantContext::runAsPlatform). The Audit module records it (plan 6.1).
 */
final readonly class PlatformContextEntered
{
    public function __construct(
        public string $reason,
        public ?string $previousTenantId,
    ) {}
}
