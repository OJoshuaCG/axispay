<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Scopes\ModeScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Test/live separation for business tables with a `livemode` column (plan
 * 6.2): reads only see the current mode, `livemode` is filled from the context
 * on create and is immutable. Use together with BelongsToTenant.
 *
 * @property bool $livemode
 *
 * @mixin Model
 */
trait BelongsToMode
{
    public static function bootBelongsToMode(): void
    {
        static::addGlobalScope(new ModeScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('livemode') === null) {
                $model->setAttribute('livemode', app(TenantContext::class)->livemode());
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('livemode')) {
                throw new LogicException('The mode (test/live) of a ['.$model::class.'] cannot be changed.');
            }
        });
    }

    public function initializeBelongsToMode(): void
    {
        $this->mergeCasts(['livemode' => 'boolean']);
    }
}
