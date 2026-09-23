<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Contracts\AllowsPlatformRows;
use App\Modules\Tenancy\Exceptions\TenantMismatchException;
use App\Modules\Tenancy\Exceptions\TenantReassignmentException;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every model on a table with `tenant_id` uses this trait (rules.md rule 2,
 * plan 6.2):
 *
 *  - reads are filtered by the current tenant and throw without a context;
 *  - `tenant_id` is filled from the context on create (an explicit value must
 *    match the context unless the platform context is active);
 *  - `tenant_id` is immutable.
 *
 * @property string|null $tenant_id
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            $context = app(TenantContext::class);
            $attributes = $model->getAttributes();
            $explicit = $attributes['tenant_id'] ?? null;

            if ($explicit === null) {
                // An explicit NULL is a platform-level row, only for models that allow it.
                if ($model instanceof AllowsPlatformRows && array_key_exists('tenant_id', $attributes)) {
                    return;
                }

                $model->setAttribute('tenant_id', $context->idOrFail($model::class));

                return;
            }

            if (! $context->isPlatformMode() && $context->hasTenant() && $context->idOrNull() !== $explicit) {
                throw new TenantMismatchException($model::class);
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new TenantReassignmentException($model::class);
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
