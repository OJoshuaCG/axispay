<?php

declare(strict_types=1);

use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookDeliveryMode;
use App\Modules\Webhooks\Enums\WebhookEventType;

/**
 * Plan 15.2: the public catalog, and every recorded business event maps to it.
 */
it('maps every domain event type to a public event type', function (DomainEventType $type): void {
    expect(WebhookEventType::fromDomain($type)->value)->toBe($type->value)
        ->and(WebhookEventType::fromDomain($type)->isSubscribable())->toBeTrue();
})->with(DomainEventType::cases());

it('lists the catalog of plan 15.2 and ADR-0069, ping included but not subscribable', function (): void {
    expect(array_map(static fn (WebhookEventType $type): string => $type->value, WebhookEventType::cases()))->toBe([
        'payment_link.created', 'payment_link.opened', 'payment_link.paid', 'payment_link.expired', 'payment_link.canceled',
        'payment.processing', 'payment.succeeded', 'payment.failed', 'payment.canceled',
        'refund.created', 'refund.succeeded', 'refund.failed',
        'dispute.created', 'dispute.closed', 'payment.pre_validation', 'ping',
    ])->and(WebhookEventType::Ping->isSubscribable())->toBeFalse()
        ->and(WebhookEventType::subscribableValues())->not->toContain('ping');
});

it('marks the pre-payment validation as the only synchronous event (ADR-0069)', function (): void {
    $synchronous = array_filter(WebhookEventType::cases(), static fn (WebhookEventType $type): bool => $type->isSynchronous());

    expect(array_values($synchronous))->toBe([WebhookEventType::PaymentPreValidation])
        ->and(WebhookEventType::PaymentPreValidation->deliveryMode())->toBe(WebhookDeliveryMode::Sync)
        ->and(WebhookEventType::PaymentSucceeded->deliveryMode())->toBe(WebhookDeliveryMode::Async)
        ->and(WebhookEventType::PaymentPreValidation->isSubscribable())->toBeTrue()
        ->and(WebhookEventType::asynchronousValues())->not->toContain('payment.pre_validation');
});
