<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a change on a freshly locked copy of an account, inside a transaction.
 * A tenant user is read inside its own tenant context (the fail-closed scope
 * stays on; no platform context is needed once the account is known).
 */
final readonly class AccountLock
{
    public function __construct(private TenantContext $context) {}

    /**
     * @template TReturn
     *
     * @param  Closure(User|PlatformAdmin): TReturn  $callback
     * @return TReturn
     */
    public function run(User|PlatformAdmin $account, Closure $callback): mixed
    {
        if ($account instanceof PlatformAdmin) {
            return DB::transaction(static fn (): mixed => $callback(
                PlatformAdmin::query()->lockForUpdate()->findOrFail($account->id),
            ));
        }

        return $this->context->runAsTenant($account->tenant_id, false, static fn (): mixed => DB::transaction(
            static fn (): mixed => $callback(User::query()->lockForUpdate()->findOrFail($account->id)),
        ));
    }
}
