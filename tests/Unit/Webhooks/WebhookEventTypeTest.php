<?php

declare(strict_types=1);

use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookEventType;

/**
 * Plan 15.2: the public catalog, and every recorded business event maps to it.
 */
it('maps every domain event type to a public event type', function (DomainEventType $type): void {
    expect(WebhookEventType::fromDomain($type)->value)->toBe($type->value)
        ->and(WebhookEventType::fromDomain($type)->isSubscribable())->toBeTrue();
})->with(DomainEventType::cases());

it('lists the catalog of plan 15.2, ping included but not subscribable', function (): void {
    expect(array_map(static fn (WebhookEventType $type): string => $type->value, WebhookEventType::cases()))->toBe([
        'payment_link.created', 'payment_link.opened', 'payment_link.paid', 'payment_link.expired', 'payment_link.canceled',
        'payment.processing', 'payment.succeeded', 'payment.failed', 'payment.canceled',
        'refund.created', 'refund.succeeded', 'refund.failed',
        'dispute.created', 'dispute.closed', 'ping',
    ])->and(WebhookEventType::Ping->isSubscribable())->toBeFalse()
        ->and(WebhookEventType::subscribableValues())->not->toContain('ping');
});
