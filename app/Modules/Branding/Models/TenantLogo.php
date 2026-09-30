<?php

declare(strict_types=1);

namespace App\Modules\Branding\Models;

use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The merchant's logo (ADR-0056 part B), one row per variant and tenant:
 * the light logo and its optional dark-theme variant. Not per mode: the same
 * logo is shown to test and live payers. `content` holds the normalized PNG
 * bytes (ImageNormalizer); it is never serialized, never selected to render
 * a page (TenantLogos) and only read to serve it. Writes go through the
 * Branding actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property LogoVariant $variant
 * @property string $version
 * @property string $mime_type
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 * @property string $sha256
 * @property string $content
 * @property string|null $uploaded_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TenantLogo extends Model
{
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    /** Every column except the image bytes: what rendering a page needs. */
    public const array METADATA_COLUMNS = ['id', 'tenant_id', 'variant', 'version', 'mime_type', 'width', 'height', 'size_bytes', 'sha256', 'created_at', 'updated_at'];

    /** Every write goes through an action with forceFill(). */
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
