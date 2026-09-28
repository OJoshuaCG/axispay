<?php

declare(strict_types=1);

namespace App\Modules\Branding\Models;

use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform logo, one row per variant (ADR-0053). A platform row: no
 * tenant, never tenant-scoped. `content` holds the normalized PNG bytes
 * (ImageNormalizer); it is never serialized and only read to serve it.
 *
 * @property string $id
 * @property LogoVariant $variant
 * @property string $version
 * @property string $mime_type
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 * @property string $sha256
 * @property string $content
 * @property string|null $uploaded_by_platform_admin_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PlatformLogo extends Model
{
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'variant' => LogoVariant::class,
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
