<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Enums\WebhookDeliveryError;

/**
 * The result of one HTTP delivery attempt (plan 15.6). `responseExcerpt`
 * is already sanitized and at most 2 KB.
 */
final readonly class DeliveryOutcome
{
    public function __construct(
        public bool $succeeded,
        public ?int $responseStatus,
        public ?string $responseExcerpt,
        public int $durationMs,
        public ?WebhookDeliveryError $error,
    ) {}

    public static function failed(WebhookDeliveryError $error, int $durationMs = 0, ?int $responseStatus = null, ?string $excerpt = null): self
    {
        return new self(false, $responseStatus, $excerpt, $durationMs, $error);
    }

    public function isRetriable(): bool
    {
        return ! $this->succeeded && ($this->error === null || $this->error->isRetriable());
    }
}
