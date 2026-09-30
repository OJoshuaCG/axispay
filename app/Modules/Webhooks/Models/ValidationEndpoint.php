<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's pre-payment validation URL in one mode (plan 7.4, 15.8; at most
 * one per tenant and mode). The secrets are encrypted with Laravel's
 * `encrypted` cast and hidden from serialization; they only leave the model
 * to sign (WebhookSigner) or once, through the actions that configure or
 * rotate them. Writes go through the Webhooks actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $url
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property bool $enabled_by_default
 * @property ValidationFailurePolicy $failure_policy
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $last_failure_at
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_alerted_at
 * @property string|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ValidationEndpoint extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected $hidden = ['secret', 'previous_secret'];

    protected $attributes = [
        'enabled_by_default' => false,
        'failure_policy' => 'fail_closed',
        'consecutive_failures' => 0,
    ];

    /**
     * The secrets that sign a call now: the current one, and the previous one
     * during the 24 hours after a rotation (plan 15.8.1, 15.5).
     *
     * @return list<string>
     */
    public function signingSecrets(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $secrets = [$this->secret];

        if ($this->previous_secret !== null && $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->greaterThan($now)) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }

    /**
     * The panel's alert (plan 15.8.5): the last calls failed in a row at
     * least `alert_after_failures` times. Validation stays active.
     */
    public function isFailing(): bool
    {
        return $this->consecutive_failures >= self::alertThreshold();
    }

    public static function alertThreshold(): int
    {
        return max(1, config()->integer('axispay.pre_payment_validation.alert_after_failures'));
    }

    /** The host of the URL, the only part of it shown in e-mails and the audit log. */
    public function host(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'immutable_datetime',
            'enabled_by_default' => 'boolean',
            'failure_policy' => ValidationFailurePolicy::class,
            'consecutive_failures' => 'integer',
            'last_failure_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_alerted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
