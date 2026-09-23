<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Tenancy\Enums\TenantStatus;

/**
 * Input of ChangeTenantStatus. Closing needs a second confirmation: the
 * operator retypes the tenant's display name (plan 21.3).
 */
final readonly class ChangeTenantStatusData
{
    public function __construct(
        public TenantStatus $status,
        public string $reason,
        public ?string $closeConfirmation = null,
    ) {}
}
