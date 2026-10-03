<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Enums\WebhookEventType;
use Carbon\CarbonImmutable;

/**
 * Filters and cursor of `GET /v1/events` (plan 10.1, 10.8). Cursors are bare
 * ULIDs of events; the list is newest first.
 */
final readonly class ListEventsData
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 100;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $startingAfter = null,
        public ?string $endingBefore = null,
        public ?WebhookEventType $type = null,
        public ?CarbonImmutable $createdGte = null,
        public ?CarbonImmutable $createdLte = null,
    ) {}
}
