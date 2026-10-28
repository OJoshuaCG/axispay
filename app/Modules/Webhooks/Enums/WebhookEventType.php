<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * Public catalog of outgoing webhook events (plan 15.2). The value is the
 * `type` of the payload and what endpoints subscribe to. Business events
 * recorded by the domain (DomainEventType) map one to one; `ping` is the
 * test event sent from the panel and cannot be subscribed to.
 */
enum WebhookEventType: string
{
    case PaymentLinkCreated = 'payment_link.created';
    case PaymentLinkOpened = 'payment_link.opened';
    case PaymentLinkPaid = 'payment_link.paid';
    case PaymentLinkExpired = 'payment_link.expired';
    case PaymentLinkCanceled = 'payment_link.canceled';
    case PaymentProcessing = 'payment.processing';
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case PaymentCanceled = 'payment.canceled';
    case RefundCreated = 'refund.created';
    case RefundSucceeded = 'refund.succeeded';
    case RefundFailed = 'refund.failed';
    case DisputeCreated = 'dispute.created';
    case DisputeClosed = 'dispute.closed';
    /**
     * The synchronous pre-payment validation (ADR-0069). It is not a business
     * event: it is never stored in the outbox, and the wildcard does not
     * include it because answering it decides whether a payment is charged.
     */
    case PaymentPreValidation = 'payment.pre_validation';
    case Ping = 'ping';

    /**
     * Subscription to every ASYNCHRONOUS event (plan 7.6 `enabled_events`);
     * `payment.pre_validation` is always chosen explicitly.
     */
    public const string WILDCARD = '*';

    public static function fromDomain(DomainEventType $type): self
    {
        return self::from($type->value);
    }

    /**
     * The key of the domain event's data that holds the event's main object
     * (`data.object` of the payload, plan 15.3).
     */
    public function objectKey(): string
    {
        return match ($this) {
            self::PaymentLinkCreated, self::PaymentLinkOpened, self::PaymentLinkPaid,
            self::PaymentLinkExpired, self::PaymentLinkCanceled => 'payment_link',
            self::PaymentProcessing, self::PaymentSucceeded, self::PaymentFailed, self::PaymentCanceled,
            self::PaymentPreValidation => 'payment',
            self::RefundCreated, self::RefundSucceeded, self::RefundFailed => 'refund',
            self::DisputeCreated, self::DisputeClosed => 'dispute',
            self::Ping => 'ping',
        };
    }

    public function isSubscribable(): bool
    {
        return $this !== self::Ping;
    }

    /** The pre-payment validation is the only event the receiver must answer. */
    public function isSynchronous(): bool
    {
        return $this === self::PaymentPreValidation;
    }

    public function deliveryMode(): WebhookDeliveryMode
    {
        return $this->isSynchronous() ? WebhookDeliveryMode::Sync : WebhookDeliveryMode::Async;
    }

    /**
     * Every type an endpoint can subscribe to, the synchronous one included.
     *
     * @return list<self>
     */
    public static function subscribable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->isSubscribable()));
    }

    /**
     * @return list<string>
     */
    public static function subscribableValues(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::subscribable());
    }

    /**
     * The subscribable types that are sent through the outbox: what the
     * wildcard covers and what the event history (`GET /v1/events`) holds.
     *
     * @return list<self>
     */
    public static function asynchronous(): array
    {
        return array_values(array_filter(self::subscribable(), static fn (self $type): bool => ! $type->isSynchronous()));
    }

    /**
     * @return list<string>
     */
    public static function asynchronousValues(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::asynchronous());
    }

    /** When the event is sent, in the reader's language (the panel's event picker). */
    public function description(): string
    {
        // The value holds a dot (`payment.succeeded`), which a translation
        // key would read as nesting: look it up in the group instead.
        $descriptions = __('webhooks.event_types');
        $description = is_array($descriptions) ? ($descriptions[$this->value] ?? null) : null;

        return is_string($description) ? $description : $this->value;
    }
}
