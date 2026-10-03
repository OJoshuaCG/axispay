<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Webhooks\Models\WebhookEvent;
use App\Modules\Webhooks\Services\EventHistory;

/**
 * `GET /v1/events/{id}` (plan 10.8): one event of the current tenant and
 * mode, or null when it does not exist, belongs to another tenant or mode,
 * is a `ping`, or is past the retention window.
 */
final readonly class RetrieveEvent
{
    public function __construct(private EventHistory $history) {}

    public function handle(string $ulid): ?WebhookEvent
    {
        return $this->history->visible()->whereKey($ulid)->first();
    }
}
