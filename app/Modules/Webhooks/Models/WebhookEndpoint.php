<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Enums\WebhookEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's webhook destination in one mode (plan 7.6, 15.1). It also
 * answers the synchronous pre-payment validation when it subscribes to
 * `payment.pre_validation` (ADR-0069): the `validation_*` columns hold that
 * part's settings and failure counters, and `validator_slot` (kept in sync
 * when saving) lets the database allow only one validator per tenant and
 * mode. The secrets are encrypted with Laravel's `encrypted` cast and hidden
 * from serialization; they only leave the model to sign (WebhookSigner) or
 * once, through the actions that create, rotate or reveal them. Writes go
 * through the Webhooks actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $livemode
 * @property string $url
 * @property string|null $description
 * @property list<string> $enabled_events
 * @property string $secret
 * @property string|null $previous_secret
 * @property CarbonImmutable|null $previous_secret_expires_at
 * @property WebhookEndpointStatus $status
 * @property CarbonImmutable|null $failing_since
 * @property CarbonImmutable|null $disabled_at
 * @property ValidationFailurePolicy $validation_failure_policy
 * @property bool $validation_enabled_by_default
 * @property int $validation_consecutive_failures
 * @property CarbonImmutable|null $validation_last_failure_at
 * @property CarbonImmutable|null $validation_last_success_at
 * @property CarbonImmutable|null $validation_last_alerted_at
 * @property int|null $validator_slot
 * @property string|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WebhookEndpoint extends Model
{
    use BelongsToMode;
    use BelongsToTenant;
    use HasUlidPrimaryKey;
    use UsesMicrosecondDates;

    protected $guarded = ['*'];

    protected $hidden = ['secret', 'previous_secret'];

    protected $attributes = [
        'validation_failure_policy' => 'fail_closed',
        'validation_enabled_by_default' => false,
        'validation_consecutive_failures' => 0,
    ];

    /** The value of `validator_slot` that marks the endpoint as the validator. */
    public const int VALIDATOR_SLOT = 1;

    protected static function booted(): void
    {
        // The unique index on (tenant, mode, validator_slot) is what stops a
        // second endpoint from subscribing to the validation.
        self::saving(static function (self $endpoint): void {
            $endpoint->validator_slot = $endpoint->validates() ? self::VALIDATOR_SLOT : null;
        });
    }

    /**
     * The endpoint that answers the pre-payment validation (at most one per
     * tenant and mode: the unique index on `validator_slot`).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeValidator(Builder $query): Builder
    {
        return $query->where('validator_slot', self::VALIDATOR_SLOT);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', WebhookEndpointStatus::Enabled->value);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_endpoint_id');
    }

    /**
     * Pre-payment validation calls sent to this endpoint, real and test.
     *
     * @return HasMany<ValidationCall, $this>
     */
    public function validationCalls(): HasMany
    {
        return $this->hasMany(ValidationCall::class, 'webhook_endpoint_id');
    }

    public function isEnabled(): bool
    {
        return $this->status->isEnabled();
    }

    public function subscribesTo(WebhookEventType $type): bool
    {
        if (! $type->isSubscribable()) {
            return false;
        }

        // The wildcard never covers the synchronous validation: it blocks
        // charges, so it is always chosen explicitly (ADR-0069).
        if ($type->isSynchronous()) {
            return in_array($type->value, $this->enabled_events, true);
        }

        return in_array(WebhookEventType::WILDCARD, $this->enabled_events, true)
            || in_array($type->value, $this->enabled_events, true);
    }

    /** Whether this endpoint answers the pre-payment validation. */
    public function validates(): bool
    {
        return $this->subscribesTo(WebhookEventType::PaymentPreValidation);
    }

    /**
     * The panel's alert (plan 15.8.5): the last validation calls failed in a
     * row at least `alert_after_failures` times. The endpoint stays active.
     */
    public function isValidationFailing(): bool
    {
        return $this->validation_consecutive_failures >= self::validationAlertThreshold();
    }

    public static function validationAlertThreshold(): int
    {
        return max(1, config()->integer('axispay.pre_payment_validation.alert_after_failures'));
    }

    /**
     * The secrets that sign a message now: the current one, and the previous
     * one during the 24 hours after a rotation (plan 15.5).
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

    /** The host of the URL, the only part of it shown in e-mails. */
    public function host(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    protected function casts(): array
    {
        return [
            'enabled_events' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'status' => WebhookEndpointStatus::class,
            'previous_secret_expires_at' => 'immutable_datetime',
            'failing_since' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'validation_failure_policy' => ValidationFailurePolicy::class,
            'validation_enabled_by_default' => 'boolean',
            'validation_consecutive_failures' => 'integer',
            'validation_last_failure_at' => 'immutable_datetime',
            'validation_last_success_at' => 'immutable_datetime',
            'validation_last_alerted_at' => 'immutable_datetime',
            'validator_slot' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
