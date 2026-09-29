<?php

declare(strict_types=1);

namespace App\Modules\Branding\Models;

use App\Modules\Branding\Enums\FaviconSize;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform favicon, one row per generated size (ADR-0053): square PNGs
 * made by ImageNormalizer::squareIcons() from one upload. A platform row: no
 * tenant, never tenant-scoped. `content` is never serialized.
 *
 * @property string $id
 * @property FaviconSize $size
 * @property string $version
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $content
 * @property string|null $uploaded_by_platform_admin_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PlatformFavicon extends Model
{
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'size' => FaviconSize::class,
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
