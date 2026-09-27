<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

use App\Modules\Gateways\Enums\ProviderEventKind;

/**
 * A verified incoming gateway event (plan 12.1 `parseWebhook`). `type` is the
 * provider's own event type, stored as is; the pipeline dispatches on `kind`.
 * `objectId` is the ID of the object the event is about, re-fetched by the
 * handler (ADR-017).
 *
 * `reducedPayload` keeps only the envelope (id, type, account, mode,
 * created, API version, object ID and type), without the object's data:
 * what is stored for events we do not handle or cannot route (plan 14.4).
 *
 * `attemptReference`: for payment events, our attempt ID read from the
 * payment's metadata in the payload. NULL means the payment was not created
 * by the platform (a "foreign object", plan 14.4): the filter runs on the
 * payload, before any call to the gateway.
 */
final readonly class ProviderWebhookEvent
{
    public function __construct(
        public string $providerEventId,
        public string $type,
        public ProviderEventKind $kind,
        public ?string $providerAccountId,
        public bool $livemode,
        public ?string $objectId,
        public string $rawPayload,
        public string $reducedPayload,
        public ?string $attemptReference = null,
    ) {}

    /** A payment event about a payment the platform did not create. */
    public function isForeignPayment(): bool
    {
        return $this->kind === ProviderEventKind::PaymentUpdated && $this->attemptReference === null;
    }

    /** Only events the platform acts on keep their full body. */
    public function storedPayload(bool $routed): string
    {
        return $routed && $this->kind !== ProviderEventKind::Unhandled && ! $this->isForeignPayment() ? $this->rawPayload : $this->reducedPayload;
    }
}
