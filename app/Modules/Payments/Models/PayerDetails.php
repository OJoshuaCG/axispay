<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The payer's data collected by the checkout (plan 7.5, 19.2): encrypted at
 * rest (`encrypted:array`), apart from the financial rows so it can be purged
 * after `purge_after` (purge job: Phase 8). Hidden from every serialization;
 * never logged.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_attempt_id
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable|null $purge_after
 * @property CarbonImmutable|null $purged_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PayerDetails extends Model
{
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $table = 'payer_details';

    protected $guarded = ['*'];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'purge_after' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
