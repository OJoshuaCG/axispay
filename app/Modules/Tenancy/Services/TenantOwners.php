<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recipients for owner notifications (plan 17.3, 22). Selecting recipients by
 * role is a notification rule from the plan, not an authorization check.
 */
final readonly class TenantOwners
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return Collection<int, User>
     */
    public function of(Tenant $tenant): Collection
    {
        return $this->context->runAsTenant(
            $tenant->id,
            $this->context->livemodeOrNull() ?? false,
            static fn (): Collection => User::role(SystemRole::Owner->value)->whereNull('disabled_at')->get(),
        );
    }
}
