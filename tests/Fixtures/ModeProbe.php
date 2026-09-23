<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only model on a throwaway table with `tenant_id` and `livemode`.
 *
 * @property string $id
 * @property string $label
 */
final class ModeProbe extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;

    public const string TABLE = 'tenancy_mode_probes';

    protected $table = self::TABLE;

    protected $guarded = [];
}
