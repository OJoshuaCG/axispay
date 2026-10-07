<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The secret that signs the proof appended to a link's `return_url` (ADR-0064),
 * one per tenant and mode. Encrypted with Laravel's `encrypted` cast and
 * hidden from serialization; it only leaves the model to sign
 * (ReturnSigningSecrets) or once, through RotateReturnSigningSecret. Writes go
 * through ReturnSigningSecrets.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ReturnSigningSecret extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected $hidden = ['secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
