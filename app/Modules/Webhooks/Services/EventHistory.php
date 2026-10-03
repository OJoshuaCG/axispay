<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the event history of the API shows (plan 10.8, ADR-0060): the events
 * of the current tenant and mode (global scopes of WebhookEvent) stored in the
 * last `axispay.api.events_retention_days` days, without the `ping` test
 * event, which is not a business event.
 */
final class EventHistory
{
    /** Microsecond-precise binding: a Carbon binding would drop the fraction. */
    public const string TIME_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * @return Builder<WebhookEvent>
     */
    public function visible(): Builder
    {
        $since = now()->subDays(max(1, config()->integer('axispay.api.events_retention_days')));

        return WebhookEvent::query()
            ->where('type', '!=', WebhookEventType::Ping->value)
            ->where('created_at', '>=', $since->utc()->format(self::TIME_FORMAT));
    }
}
