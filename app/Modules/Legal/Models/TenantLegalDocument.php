<?php

declare(strict_types=1);

namespace App\Modules\Legal\Models;

use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The merchant's privacy notice or terms and conditions (ADR-0056), one row
 * per kind and tenant: a text (`body`, simple Markdown) or a link (`url`).
 * Not per mode: the same document is shown to test and live payers. Writes
 * go through the Legal actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property LegalDocumentKind $kind
 * @property LegalDocumentFormat $format
 * @property string|null $body
 * @property string|null $url
 * @property string|null $updated_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class TenantLegalDocument extends Model
{
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    /** Every write goes through an action with forceFill(). */
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
