<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

use App\Modules\Identity\Data\IssuedInvitation;
use App\Modules\Tenancy\Models\Tenant;

/**
 * Result of CreateTenant: the new tenant and its first owner invitation,
 * whose link the platform panel shows once (ADR-0068).
 */
final readonly class CreatedTenant
{
    public function __construct(
        public Tenant $tenant,
        public IssuedInvitation $ownerInvitation,
    ) {}
}
