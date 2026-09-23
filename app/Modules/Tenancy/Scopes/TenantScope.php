<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Fail-closed tenant filter (plan 6.2). Without a tenant context the query
 * throws instead of returning every tenant's rows. The platform context
 * (TenantContext::runAsPlatform, audited) lifts the filter.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatformMode()) {
            return;
        }

        $tenantId = $context->idOrNull() ?? throw new MissingTenantContextException($model::class);

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
