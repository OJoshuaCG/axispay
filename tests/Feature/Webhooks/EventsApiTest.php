<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Models\WebhookEvent;
use App\Modules\Webhooks\Services\WebhookPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\withHeaders;

/**
 * GET /v1/events and GET /v1/events/{id} (plan 10.1, 10.8, ADR-0060): the
 * event history of the integrator. An event is the frozen webhook body,
 * byte for byte.
 */

/**
 * @return TestResponse<Response>
 */
function listEvents(string $key, string $query = ''): TestResponse
{
    return withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/events'.($query !== '' ? '?'.$query : '')));
}

function eventUrl(WebhookEvent $event): string
{
    return apiUrl('v1/events/'.PrefixedId::encode(ResourceType::Event, $event->id));
}

/**
 * A business event recorded the way the domain does, returned as stored.
 */
function recordedEvent(Tenant $tenant, DomainEventType $type = DomainEventType::PaymentLinkOpened, bool $livemode = false): WebhookEvent
{
    WebhookTestHelpers::record($tenant, $type, livemode: $livemode);

    $events = WebhookTestHelpers::events($tenant, $livemode);

    return $events[count($events) - 1];
}

/** Moves an event's creation time (the retention window and `created` filters use it). */
function stampEvent(Tenant $tenant, WebhookEvent $event, string $at, bool $livemode = false): void
{
    WebhookTestHelpers::in($tenant, $livemode, static function () use ($event, $at): void {
        $row = WebhookEvent::query()->findOrFail($event->id);
        $row->forceFill(['created_at' => CarbonImmutable::parse($at)])->save();
    });
}

/**
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function listedEventIds(TestResponse $response): array
{
    return ApiTestHelpers::listed($response);
}

function eventId(WebhookEvent $event): string
{
    return PrefixedId::encode(ResourceType::Event, $event->id);
}

it('retrieves an event with exactly the body that is sent by webhook', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $event = recordedEvent($tenant);

    $response = withHeaders(ApiTestHelpers::headers($key))->getJson(eventUrl($event))->assertOk();

    expect($response->getContent())->toBe($event->payload)
        ->and($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->json('id'))->toBe(eventId($event))
        ->and($response->json('type'))->toBe('payment_link.opened')
        ->and($response->json('api_version'))->toBe('v1')
        ->and($response->json('livemode'))->toBeFalse()
        ->and($response->json('data.object.object'))->toBe('payment_link');
});

it('lists events with the frozen bodies inside the list envelope', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $first = recordedEvent($tenant, DomainEventType::PaymentLinkOpened);
    $second = recordedEvent($tenant, DomainEventType::PaymentLinkPaid);

    $response = listEvents($key)->assertOk()->assertJsonPath('object', 'list')->assertJsonPath('has_more', false);

    expect(listedEventIds($response))->toBe([eventId($second), eventId($first)])
        ->and($response->getContent())->toContain($first->payload)->toContain($second->payload)
        ->and($response->json('data.0'))->toBe(json_decode($second->payload, true))
        ->and($response->json('data.1'))->toBe(json_decode($first->payload, true));
});

it('answers an empty list when there are no events', function (): void {
    [, $key] = ApiTestHelpers::key(ApiTestHelpers::readyTenant());

    listEvents($key)->assertOk()->assertExactJson(['object' => 'list', 'data' => [], 'has_more' => false]);
});

it('lists events newest first with has_more and both cursors', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $events = [];

    foreach (range(1, 3) as $i) {
        $events[] = recordedEvent($tenant);
    }

    $response = listEvents($key, 'limit=2')->assertOk()->assertJsonPath('has_more', true);
    expect(listedEventIds($response))->toBe([eventId($events[2]), eventId($events[1])]);

    $next = listEvents($key, 'limit=2&starting_after='.eventId($events[1]))->assertJsonPath('has_more', false);
    expect(listedEventIds($next))->toBe([eventId($events[0])]);

    $previous = listEvents($key, 'limit=1&ending_before='.eventId($events[0]))->assertJsonPath('has_more', true);
    expect(listedEventIds($previous))->toBe([eventId($events[1])]);

    $top = listEvents($key, 'limit=5&ending_before='.eventId($events[0]))->assertJsonPath('has_more', false);
    expect(listedEventIds($top))->toBe([eventId($events[2]), eventId($events[1])]);
});

it('returns 20 events by default and at most 100', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    foreach (range(1, 21) as $i) {
        recordedEvent($tenant);
    }

    listEvents($key)->assertJsonCount(20, 'data')->assertJsonPath('has_more', true);
    listEvents($key, 'limit=100')->assertJsonCount(21, 'data')->assertJsonPath('has_more', false);
});

it('refuses a bad limit or cursor with 400 parameter_invalid', function (string $query, string $param): void {
    [, $key] = ApiTestHelpers::key(ApiTestHelpers::readyTenant());

    listEvents($key, $query)
        ->assertStatus(400)
        ->assertJsonPath('error.code', ApiErrorCode::ParameterInvalid->value)
        ->assertJsonPath('error.param', $param);
})->with([
    'limit zero' => ['limit=0', 'limit'],
    'limit too big' => ['limit=101', 'limit'],
    'limit text' => ['limit=ten', 'limit'],
    'cursor of another type' => ['starting_after=plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S', 'starting_after'],
    'both cursors' => ['starting_after=evt_01J8Z3Q6T4Y0V8KX2M1N5P7R9S&ending_before=evt_01J8Z3Q6T4Y0V8KX2M1N5P7R9T', 'ending_before'],
    'unknown type' => ['type=payment.exploded', 'type'],
    'ping is not listable' => ['type=ping', 'type'],
    'bad date' => ['created[gte]=yesterday', 'created[gte]'],
    'created not as a range' => ['created=2026-09-01', 'created'],
]);

it('filters by type', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $opened = recordedEvent($tenant, DomainEventType::PaymentLinkOpened);
    $paid = recordedEvent($tenant, DomainEventType::PaymentLinkPaid);

    expect(listedEventIds(listEvents($key, 'type=payment_link.paid')))->toBe([eventId($paid)])
        ->and(listedEventIds(listEvents($key, 'type=payment_link.opened')))->toBe([eventId($opened)])
        ->and(listedEventIds(listEvents($key, 'type=payment.failed')))->toBe([]);
});

it('filters by creation date with Unix seconds or ISO-8601', function (): void {
    Carbon::setTestNow('2026-10-02 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $old = recordedEvent($tenant);
    stampEvent($tenant, $old, '2026-09-20 12:00:00');
    $recent = recordedEvent($tenant);
    stampEvent($tenant, $recent, '2026-09-25 12:00:00.500000');

    expect(listedEventIds(listEvents($key, 'created[lte]=2026-09-21T00:00:00Z')))->toBe([eventId($old)])
        ->and(listedEventIds(listEvents($key, 'created[gte]='.Carbon::parse('2026-09-24 00:00:00')->getTimestamp())))->toBe([eventId($recent)])
        ->and(listedEventIds(listEvents($key, 'created[gte]=2026-09-01T00:00:00Z&created[lte]=2026-09-30T00:00:00Z')))->toBe([eventId($recent), eventId($old)])
        // The comparison keeps the fraction of a second: 12:00:00.5 is after 12:00:00.
        ->and(listedEventIds(listEvents($key, 'created[lte]=2026-09-25T12:00:00Z')))->toBe([eventId($old)])
        ->and(listedEventIds(listEvents($key, 'created[gte]=2026-10-01T00:00:00Z')))->toBe([]);
});

it('requires the events:read scope on both endpoints', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $withoutScope] = ApiTestHelpers::key($tenant, scopes: [ApiScope::LinksRead, ApiScope::PaymentsRead]);
    [, $withScope] = ApiTestHelpers::key($tenant, scopes: [ApiScope::EventsRead]);
    $event = recordedEvent($tenant);

    foreach ([apiUrl('v1/events'), eventUrl($event)] as $url) {
        withHeaders(ApiTestHelpers::headers($withoutScope))->getJson($url)
            ->assertForbidden()
            ->assertJsonPath('error.code', ApiErrorCode::InsufficientScope->value)
            ->assertJsonPath('error.type', 'permission_error');
        withHeaders(ApiTestHelpers::headers($withScope))->getJson($url)->assertOk();
    }

    // events:read opens nothing else.
    withHeaders(ApiTestHelpers::headers($withScope))->getJson(apiUrl('v1/payment_links'))->assertForbidden();
});

it('needs a valid API key', function (): void {
    withHeaders(['Accept' => 'application/json'])->getJson(apiUrl('v1/events'))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', ApiErrorCode::InvalidApiKey->value);
});

it('counts events towards the per-key rate limit and sends the RateLimit headers', function (): void {
    config(['axispay.api.rate_limit_per_minute.test' => 2]);
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $event = recordedEvent($tenant);

    listEvents($key)->assertOk()->assertHeader('RateLimit-Limit', '2')->assertHeader('RateLimit-Remaining', '1')->assertHeader('Request-Id');
    withHeaders(ApiTestHelpers::headers($key))->getJson(eventUrl($event))->assertOk()->assertHeader('RateLimit-Remaining', '0');
    listEvents($key)->assertStatus(429)->assertJsonPath('error.code', ApiErrorCode::RateLimited->value)->assertHeader('Retry-After');
});

it('answers 404 for a wrong prefix, a bare ULID or an unknown ID', function (string $id): void {
    [, $key] = ApiTestHelpers::key(ApiTestHelpers::readyTenant());

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/events/'.$id))
        ->assertNotFound()
        ->assertJsonPath('error.code', ApiErrorCode::ResourceNotFound->value);
})->with([
    'plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    '01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    'evt_01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    'evt_01j8z3q6t4y0v8kx2m1n5p7r9s',
]);

it('never shows or lists another tenant\'s events (plan 6.6)', function (): void {
    [$a, $b] = [ApiTestHelpers::readyTenant(), ApiTestHelpers::readyTenant()];
    [, $keyA] = ApiTestHelpers::key($a);
    $own = recordedEvent($a);
    $foreign = recordedEvent($b);

    withHeaders(ApiTestHelpers::headers($keyA))->getJson(eventUrl($foreign))
        ->assertNotFound()
        ->assertJsonPath('error.code', ApiErrorCode::ResourceNotFound->value);
    withHeaders(ApiTestHelpers::headers($keyA))->getJson(eventUrl($own))->assertOk();

    expect(listedEventIds(listEvents($keyA)))->toBe([eventId($own)])
        // The foreign ID as a cursor leaks nothing: it only is a position in time.
        ->and(listedEventIds(listEvents($keyA, 'ending_before='.eventId($foreign))))->toBe([])
        ->and(listedEventIds(listEvents($keyA, 'type=payment_link.opened')))->toBe([eventId($own)]);
});

it('keeps test and live events apart', function (): void {
    $tenant = ApiTestHelpers::readyTenant(livemode: true);
    [, $testKey] = ApiTestHelpers::key($tenant);
    [, $liveKey] = ApiTestHelpers::key($tenant, livemode: true);
    $testEvent = recordedEvent($tenant);
    $liveEvent = recordedEvent($tenant, livemode: true);

    expect(listedEventIds(listEvents($testKey)))->toBe([eventId($testEvent)])
        ->and(listedEventIds(listEvents($liveKey)))->toBe([eventId($liveEvent)]);

    withHeaders(ApiTestHelpers::headers($testKey))->getJson(eventUrl($liveEvent))->assertNotFound();
    withHeaders(ApiTestHelpers::headers($liveKey))->getJson(eventUrl($testEvent))->assertNotFound();
    withHeaders(ApiTestHelpers::headers($liveKey))->getJson(eventUrl($liveEvent))->assertOk()->assertJsonPath('livemode', true);
});

it('shows the history even when no endpoint was subscribed', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $event = recordedEvent($tenant);

    expect(WebhookTestHelpers::deliveries($tenant))->toBe([]);
    withHeaders(ApiTestHelpers::headers($key))->getJson(eventUrl($event))->assertOk();
});

it('hides the ping test event', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $business = recordedEvent($tenant);

    $ping = WebhookTestHelpers::in($tenant, false, static function (): WebhookEvent {
        $id = strtoupper((string) Str::ulid());
        $event = new WebhookEvent;
        $event->forceFill([
            'id' => $id,
            'livemode' => false,
            'type' => WebhookEventType::Ping,
            'payload' => WebhookPayload::ping($id, false, CarbonImmutable::now()),
        ])->save();

        return $event;
    });

    expect(listedEventIds(listEvents($key)))->toBe([eventId($business)]);
    withHeaders(ApiTestHelpers::headers($key))->getJson(eventUrl($ping))->assertNotFound();
});

it('shows only the last 30 days', function (): void {
    Carbon::setTestNow('2026-10-02 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $expired = recordedEvent($tenant);
    stampEvent($tenant, $expired, '2026-09-02 11:59:59');
    $edge = recordedEvent($tenant);
    stampEvent($tenant, $edge, '2026-09-02 12:00:00');

    expect(listedEventIds(listEvents($key)))->toBe([eventId($edge)]);
    withHeaders(ApiTestHelpers::headers($key))->getJson(eventUrl($expired))->assertNotFound();
    withHeaders(ApiTestHelpers::headers($key))->getJson(eventUrl($edge))->assertOk();

    config(['axispay.api.events_retention_days' => 60]);

    expect(listedEventIds(listEvents($key)))->toBe([eventId($edge), eventId($expired)]);
});

it('never exposes Stripe identifiers', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    recordedEvent($tenant, DomainEventType::PaymentLinkPaid);

    $content = (string) listEvents($key)->assertOk()->getContent();

    expect(preg_match('/\b(pi|ch|acct|cus|pm|py|dp)_[A-Za-z0-9]{14,}\b/', $content))->toBe(0);
    expect(str_contains($content, 'stripe'))->toBeFalse();
});
