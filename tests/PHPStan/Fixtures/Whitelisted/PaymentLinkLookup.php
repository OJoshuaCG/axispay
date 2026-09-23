<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Scopes\TenantScope;

/**
 * Fixture: a whitelisted class (config/tenancy.php) may bypass the scope.
 */
final class PaymentLinkLookup
{
    public function find(): void
    {
        User::query()->withoutGlobalScope(TenantScope::class)->get();
    }
}
