<?php

declare(strict_types=1);

use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\Support\WebhookTestHelpers;

/**
 * Plan 15.6 / ADR-0067: the webhook delivery log is kept 30 days. Older
 * final attempts of every tenant are deleted; pending ones never are.
 */
beforeEach(function (): void {
    FakeHostResolver::install();
    Http::fake(['*' => Http::response('ok')]);
});

it('deletes final delivery attempts past the retention of every tenant and keeps the rest', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    WebhookTestHelpers::endpoint($a);
    WebhookTestHelpers::endpoint($b, livemode: true);

    Carbon::setTestNow(now()->subDays(31));
    WebhookTestHelpers::record($a, DomainEventType::PaymentLinkOpened);
    Carbon::setTestNow(now()->addSecond());
    WebhookTestHelpers::record($a, DomainEventType::PaymentLinkOpened);
    WebhookTestHelpers::record($b, DomainEventType::PaymentLinkOpened, livemode: true);
    Carbon::setTestNow(now()->addDays(2));
    WebhookTestHelpers::record($a, DomainEventType::PaymentLinkOpened);
    Carbon::setTestNow();

    [$oldA, $oldPendingA, $recentA] = WebhookTestHelpers::deliveries($a);
    expect(WebhookTestHelpers::deliveries($b, livemode: true))->toHaveCount(1);
    WebhookTestHelpers::in($a, false, static fn (): bool => WebhookDelivery::query()->findOrFail($oldPendingA->id)
        ->forceFill(['status' => WebhookDeliveryStatus::Pending])->save());

    artisanCommand('axispay:webhook-deliveries:purge')->assertSuccessful();

    $remainingA = array_map(static fn (WebhookDelivery $delivery): string => $delivery->id, WebhookTestHelpers::deliveries($a));
    expect($oldA->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($remainingA)->toBe([$oldPendingA->id, $recentA->id])
        ->and(WebhookTestHelpers::deliveries($b, livemode: true))->toBe([])
        // The outbox itself is kept; GET /v1/events has its own window.
        ->and(WebhookTestHelpers::events($a))->toHaveCount(3);

    // Idempotent: a second run deletes nothing more.
    artisanCommand('axispay:webhook-deliveries:purge')->assertSuccessful();
    expect(WebhookTestHelpers::deliveries($a))->toHaveCount(2);
});

it('is scheduled daily without overlapping, on one server', function (): void {
    $event = collect(app(Schedule::class)->events())->firstOrFail(static fn (Event $event): bool => str_contains((string) $event->command, 'axispay:webhook-deliveries:purge'));

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
