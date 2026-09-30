<?php

declare(strict_types=1);

namespace App\Modules\Legal\Models;

use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The platform's own privacy notice or terms and conditions (ADR-0056), one
 * row per kind. A platform row: no tenant, never tenant-scoped. Shown on the
 * pay host's `/legal` page.
 *
 * @property string $id
 * @property LegalDocumentKind $kind
 * @property LegalDocumentFormat $format
 * @property string|null $body
 * @property string|null $url
 * @property string|null $updated_by_platform_admin_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PlatformLegalDocument extends Model
{
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'kind' => LegalDocumentKind::class,
            'format' => LegalDocumentFormat::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
