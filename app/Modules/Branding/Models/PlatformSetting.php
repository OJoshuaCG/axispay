<?php

declare(strict_types=1);

namespace App\Modules\Branding\Models;

use App\Modules\Shared\Database\UsesMicrosecondDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform-level setting managed by the superadmin (ADR-0053): not per
 * tenant, changed without a deploy, audited by the action that changes it.
 *
 * @property string $key
 * @property mixed $value
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PlatformSetting extends Model
{
    use UsesMicrosecondDates;

    public const string BRAND_DISPLAY_MODE = 'brand_display_mode';

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
