<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Gateways\Enums\ProviderFailureKind;
use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One decline of an attempt (plan 9.2). Shown to the tenant in the panel;
 * payers only see a generic message (plan 11.7 rule 7). The unique
 * `(payment_attempt_id, provider_reference)` records each decline once.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $payment_attempt_id
 * @property string $provider_reference
 * @property string|null $code
 * @property string|null $decline_code
 * @property ProviderFailureKind|null $kind
 * @property string|null $message
 * @property string|null $card_country
 * @property string|null $card_brand
 * @property string|null $card_fingerprint forensics only (ADR-0051)
 * @property string|null $client_ip
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentAttemptFailure extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    public const int PROVIDER_REFERENCE_MAX = 255;

    protected $guarded = ['*'];

    protected $hidden = ['client_ip'];

    protected function casts(): array
    {
        return [
            'kind' => ProviderFailureKind::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
