<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Scopes;

use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Test/live separation (plan 6.2, rules.md rule 4): rows of the other mode are
 * never visible. Fail-closed like TenantScope.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class ModeScope implements Scope
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

        $livemode = $context->livemodeOrNull() ?? throw new MissingTenantContextException($model::class);

        $builder->where($model->qualifyColumn('livemode'), $livemode);
    }
}
