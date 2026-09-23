<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * User provider for the `web` guard. Tenant users are tenant-scoped, but
 * authentication runs before the tenant is known (the tenant is derived from
 * the user, plan 6.3), so this provider — like ApiKeyAuthenticator for the
 * API — is an allowed entry point that looks users up without the tenant
 * scope. It only resolves a user by ID, credentials or remember token; the
 * tenant context is set right after by ResolveTenantContext.
 */
final class TenantUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null): Builder
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
