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
 *
 * `providerPaymentId`: for refund and dispute events, the gateway's ID of the
 * payment they are about, read from the payload. Those objects do not carry
 * our metadata, so the platform finds its own attempt by this ID instead; a
 * payment it holds no attempt for is a foreign object too.
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
        public ?string $providerPaymentId = null,
    ) {}

    /** A payment event about a payment the platform did not create. */
    public function isForeignPayment(): bool
    {
        return $this->kind === ProviderEventKind::PaymentUpdated && $this->attemptReference === null;
    }

    /**
     * A refund or dispute event: about a payment found by its gateway ID, not
     * by metadata of ours (see `providerPaymentId`).
     */
    public function isAboutPaymentById(): bool
    {
        return in_array($this->kind, [ProviderEventKind::RefundUpdated, ProviderEventKind::PaymentRefundsChanged, ProviderEventKind::DisputeUpdated], true);
    }

    /**
     * Only account events the platform acts on keep their full body. Payment
     * events keep the reduced envelope too (ADR-0051): the handler re-reads
     * the payment from the gateway (rules.md rule 6), and the body would
     * carry payer data (billing details, receipt e-mail).
     */
    public function storedPayload(bool $routed): string
    {
        return $this->keepsFullPayload($routed) ? $this->rawPayload : $this->reducedPayload;
    }

    public function keepsFullPayload(bool $routed): bool
    {
        return $routed && in_array($this->kind, [ProviderEventKind::AccountUpdated, ProviderEventKind::AccountDeauthorized], true);
    }
}
