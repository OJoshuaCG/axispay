<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Models;

use App\Modules\Shared\Database\HasUlidPrimaryKey;
use App\Modules\Shared\Database\UsesMicrosecondDates;
use App\Modules\Tenancy\Concerns\BelongsToMode;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Enums\WebhookEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's webhook destination in one mode (plan 7.6, 15.1). The secrets
 * are encrypted with Laravel's `encrypted` cast and hidden from
 * serialization; they only leave the model to sign (WebhookSigner) or once,
 * through the actions that create, rotate or reveal them. Writes go through
 * the Webhooks actions.
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

    public function isEnabled(): bool
    {
        return $this->status->isEnabled();
    }

    public function subscribesTo(WebhookEventType $type): bool
    {
        if (! $type->isSubscribable()) {
            return false;
        }

        return in_array(WebhookEventType::WILDCARD, $this->enabled_events, true)
            || in_array($type->value, $this->enabled_events, true);
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
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
