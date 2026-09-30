<?php

declare(strict_types=1);

use App\Modules\Shared\Time\IsoDateTime;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\DomainEvent;
use App\Modules\Webhooks\Models\WebhookEvent;
use App\Modules\Webhooks\Services\DomainEventRecorder;
use App\Modules\Webhooks\Services\WebhookOutbox;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeHostResolver;
use Tests\Support\WebhookTestHelpers;

/**
 * Plan 15.3 / 15.4: the outbox. A business event becomes, in the same
 * transaction, a frozen webhook event and one pending delivery per
 * subscribed endpoint; jobs are queued after the commit; a sweeper recovers
 * what was never published or never queued.
 */
beforeEach(function (): void {
    FakeHostResolver::install();
});

it('fans a business event out to the enabled, subscribed endpoints of its mode', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    $all = WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::endpoint($tenant, attributes: ['enabled_events' => ['payment_link.paid']]);
    WebhookTestHelpers::endpoint($tenant, attributes: ['status' => WebhookEndpointStatus::DisabledByUser]);
    WebhookTestHelpers::endpoint($tenant, attributes: ['status' => WebhookEndpointStatus::DisabledByFailures]);
    WebhookTestHelpers::endpoint($tenant, livemode: true);

    $domain = WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

    [$event] = WebhookTestHelpers::events($tenant);
    $deliveries = WebhookTestHelpers::deliveries($tenant);

    expect(WebhookTestHelpers::events($tenant))->toHaveCount(1)
        ->and($event->type)->toBe(WebhookEventType::PaymentLinkOpened)
        ->and($event->domain_event_id)->toBe($domain->id)
        ->and($event->dispatched_at)->not->toBeNull()
        ->and($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->webhook_endpoint_id)->toBe($all->id)
        ->and($deliveries[0]->trigger)->toBe(WebhookDeliveryTrigger::Automatic)
        ->and($deliveries[0]->attempt_number)->toBe(1)
        ->and($deliveries[0]->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and(WebhookTestHelpers::in($tenant, false, static fn () => DomainEvent::query()->findOrFail($domain->id)->published_at))->not->toBeNull()
        ->and(WebhookTestHelpers::events($tenant, livemode: true))->toBe([]);

    Http::assertSentCount(1);
});

it('freezes the payload of plan 15.3 and keeps empty objects as objects', function (): void {
    Carbon::setTestNow('2026-10-06 02:11:10.123456');
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);

    $domain = WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$event] = WebhookTestHelpers::events($tenant);
    $payload = jsonArray($event->payload);

    expect($payload)->toBe([
        'id' => $event->prefixedId(),
        'type' => 'payment_link.opened',
        'api_version' => 'v1',
        'livemode' => false,
        'created_at' => IsoDateTime::format($domain->occurred_at),
        'data' => [
            'object' => [
                'id' => 'plink_01J8Z5Q6T4Y0V8KX2M1N5P7R9S',
                'object' => 'payment_link',
                'amount' => '150.00',
                'currency' => 'MXN',
                'metadata' => [],
            ],
            'open_count' => 1,
            'first_open' => true,
        ],
    ])->and($event->payload)->toContain('"metadata":{}')
        ->and($event->prefixedId())->toStartWith('evt_')
        ->and($payload['created_at'])->toBe('2026-10-06T02:11:10Z');
});

it('signs every delivery with the Standard Webhooks headers over the exact body', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$event] = WebhookTestHelpers::events($tenant);

    Http::assertSent(static function (Request $request) use ($endpoint, $event): bool {
        $timestamp = (int) WebhookTestHelpers::header($request, 'webhook-timestamp');

        return $request->method() === 'POST'
            && $request->url() === WebhookTestHelpers::URL
            && $request->body() === $event->payload
            && WebhookTestHelpers::header($request, 'webhook-id') === $event->prefixedId()
            && WebhookTestHelpers::header($request, 'content-type') === 'application/json'
            && WebhookTestHelpers::header($request, 'user-agent') === 'AxisPay-Webhooks/1.0'
            && abs($timestamp - now()->getTimestamp()) <= 1
            && app(WebhookSigner::class)->verify($endpoint->secret, $event->prefixedId(), $timestamp, $request->body(), WebhookTestHelpers::header($request, 'webhook-signature'), now()->getTimestamp());
    });
});

it('writes nothing and sends nothing when the business transaction rolls back', function (): void {
    Http::fake();
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);

    try {
        WebhookTestHelpers::in($tenant, false, static fn () => DB::transaction(static function (): void {
            app(DomainEventRecorder::class)->record(DomainEventType::PaymentLinkOpened, 'payment_link', '01J8Z5Q6T4Y0V8KX2M1N5P7R9S', WebhookTestHelpers::linkData());

            throw new RuntimeException('The change failed.');
        }));
    } catch (RuntimeException) {
    }

    expect(WebhookTestHelpers::events($tenant))->toBe([])
        ->and(WebhookTestHelpers::deliveries($tenant))->toBe([]);
    Http::assertNothingSent();
});

it('keeps test and live apart: an event only reaches endpoints of its own mode', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    $test = WebhookTestHelpers::endpoint($tenant, livemode: false);
    $live = WebhookTestHelpers::endpoint($tenant, livemode: true);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened, livemode: true);

    $liveDeliveries = WebhookTestHelpers::deliveries($tenant, livemode: true);
    expect($liveDeliveries)->toHaveCount(1)
        ->and($liveDeliveries[0]->webhook_endpoint_id)->toBe($live->id)
        ->and(WebhookTestHelpers::deliveries($tenant, livemode: false))->toBe([])
        ->and(WebhookTestHelpers::events($tenant, livemode: false))->toBe([])
        ->and(jsonArray(WebhookTestHelpers::events($tenant, livemode: true)[0]->payload)['livemode'] ?? null)->toBeTrue()
        ->and($test->livemode)->toBeFalse();
});

it('records the webhook event even when no endpoint is subscribed', function (): void {
    Http::fake();
    $tenant = activeTenant();

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

    expect(WebhookTestHelpers::events($tenant))->toHaveCount(1)
        ->and(WebhookTestHelpers::deliveries($tenant))->toBe([]);
    Http::assertNothingSent();
});

it('queues one job per delivery after the commit, carrying identifiers only', function (): void {
    Queue::fake();
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$delivery] = WebhookTestHelpers::deliveries($tenant);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->queued_at)->not->toBeNull();

    Queue::assertPushed(DeliverWebhookJob::class, 1);
    Queue::assertPushed(DeliverWebhookJob::class, static function (DeliverWebhookJob $job) use ($delivery, $endpoint, $tenant): bool {
        $serialized = serialize($job);

        return $job->webhookDeliveryId === $delivery->id
            && $job->tenantId() === $tenant->id
            && $job->livemode() === false
            && ! str_contains($serialized, $endpoint->secret)
            && ! str_contains($serialized, 'plink_')
            && ! str_contains($serialized, WebhookTestHelpers::URL);
    });
});

it('publishes, from the sweeper, domain events that were never published', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant, attributes: ['created_at' => now()->subHour()]);

    // A row recorded before Phase 5 (no publication).
    $domain = WebhookTestHelpers::in($tenant, false, static function (): DomainEvent {
        $event = new DomainEvent;
        $event->forceFill([
            'type' => DomainEventType::PaymentLinkOpened,
            'subject_type' => 'payment_link',
            'subject_id' => '01J8Z5Q6T4Y0V8KX2M1N5P7R9S',
            'data' => WebhookTestHelpers::linkData(),
            'occurred_at' => now()->subMinutes(10),
        ])->save();

        return $event;
    });

    artisanCommand('axispay:webhooks:sweep')->assertSuccessful();

    $events = WebhookTestHelpers::events($tenant);
    expect($events)->toHaveCount(1)
        ->and($events[0]->domain_event_id)->toBe($domain->id)
        ->and(WebhookTestHelpers::deliveries($tenant)[0]->status ?? null)->toBe(WebhookDeliveryStatus::Succeeded);

    // A second run publishes nothing again.
    artisanCommand('axispay:webhooks:sweep')->assertSuccessful();
    expect(WebhookTestHelpers::events($tenant))->toHaveCount(1);
    Http::assertSentCount(1);
});

it('never sends an endpoint the events that happened before it was registered (ADR-0057)', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    $older = WebhookTestHelpers::endpoint($tenant, attributes: ['created_at' => now()->subDay()]);
    WebhookTestHelpers::endpoint($tenant, attributes: ['url' => 'https://new.merchant.example/hook']);

    // A backlog event from an hour ago, published late by the sweeper.
    WebhookTestHelpers::in($tenant, false, static function (): void {
        $event = new DomainEvent;
        $event->forceFill([
            'type' => DomainEventType::PaymentLinkOpened,
            'subject_type' => 'payment_link',
            'subject_id' => '01J8Z5Q6T4Y0V8KX2M1N5P7R9S',
            'data' => WebhookTestHelpers::linkData(),
            'occurred_at' => now()->subHour(),
        ])->save();
    });

    artisanCommand('axispay:webhooks:sweep')->assertSuccessful();

    $deliveries = WebhookTestHelpers::deliveries($tenant);
    expect(WebhookTestHelpers::events($tenant))->toHaveCount(1)
        ->and($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->webhook_endpoint_id)->toBe($older->id);

    // An event from now on reaches both.
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    expect(WebhookTestHelpers::deliveries($tenant))->toHaveCount(3);
});

it('queues again, from the sweeper, a due delivery whose job was lost, and only that one', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');
    Queue::fake();
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    Queue::assertPushed(DeliverWebhookJob::class, 1);

    // Queued a moment ago: not touched.
    artisanCommand('axispay:webhooks:sweep')->assertSuccessful();
    Queue::assertPushed(DeliverWebhookJob::class, 1);

    // Its job never ran.
    Carbon::setTestNow('2026-10-06 12:03:00');
    artisanCommand('axispay:webhooks:sweep')->assertSuccessful();
    Queue::assertPushed(DeliverWebhookJob::class, 2);
});

it('never publishes a webhook event twice for the same domain event', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);
    $domain = WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

    WebhookTestHelpers::in($tenant, false, static fn () => DB::transaction(static fn () => app(WebhookOutbox::class)->publish(DomainEvent::query()->findOrFail($domain->id))));

    expect(WebhookTestHelpers::in($tenant, false, static fn () => WebhookEvent::query()->where('domain_event_id', $domain->id)->count()))->toBe(1)
        ->and(WebhookTestHelpers::deliveries($tenant))->toHaveCount(1);
});
