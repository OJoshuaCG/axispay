<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Webhooks\Actions\DeliverWebhook;
use App\Modules\Webhooks\Actions\ResendWebhookDelivery;
use App\Modules\Webhooks\Actions\SendTestWebhook;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookDeliveryError;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Notifications\WebhookEndpointNotification;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeHostResolver;
use Tests\Support\WebhookTestHelpers;

/**
 * Plan 15.5 - 15.7: delivery, retries, SSRF at delivery time, disabling
 * after continuous failures, manual resend and the test event.
 */
beforeEach(function (): void {
    FakeHostResolver::install();
    Notification::fake();
});

/**
 * Sends the next pending automatic attempt at its scheduled time.
 */
function sendNextAttempt(WebhookEndpoint $endpoint): ?WebhookDelivery
{
    return WebhookTestHelpers::in($endpoint->tenant_id, $endpoint->livemode, static function () use ($endpoint): ?WebhookDelivery {
        $pending = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->where('status', WebhookDeliveryStatus::Pending->value)
            ->orderBy('scheduled_at')
            ->first();

        if ($pending === null) {
            return null;
        }

        Carbon::setTestNow($pending->scheduled_at);

        return app(DeliverWebhook::class)->handle($pending->id);
    });
}

it('marks a 2xx as succeeded and keeps a sanitized excerpt of at most 2 KB', function (): void {
    Http::fake(['*' => Http::response(str_repeat('é', 3000)."\x07", 202)]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$delivery] = WebhookTestHelpers::deliveries($tenant);

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->response_status)->toBe(202)
        ->and($delivery->error)->toBeNull()
        ->and(strlen((string) $delivery->response_body_excerpt))->toBeLessThanOrEqual(2048)
        ->and(mb_check_encoding((string) $delivery->response_body_excerpt, 'UTF-8'))->toBeTrue()
        ->and($delivery->duration_ms)->toBeInt()
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->lease_until)->toBeNull();
});

it('redacts secrets and personal data echoed in the response excerpt', function (): void {
    Http::fake(['*' => Http::response('bad signature for whsec_c2VjcmV0c2VjcmV0 from ana@example.com', 400)]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$delivery] = WebhookTestHelpers::deliveries($tenant);

    expect($delivery->response_body_excerpt)->not->toContain('whsec_c2VjcmV0c2VjcmV0')
        ->and($delivery->response_body_excerpt)->not->toContain('ana@example.com')
        ->and($delivery->error)->toBe(WebhookDeliveryError::HttpStatus);
});

it('retries on the schedule of plan 15.6 and abandons after the 8th attempt', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');
    Http::fake(['*' => Http::response('down', 500)]);
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

    while (sendNextAttempt($endpoint) !== null) {
        // Each attempt schedules the next one until the schedule ends.
    }

    $deliveries = WebhookTestHelpers::deliveries($tenant);
    $gaps = [];

    for ($i = 1; $i < count($deliveries); $i++) {
        $gaps[] = (int) $deliveries[$i - 1]->scheduled_at->diffInSeconds($deliveries[$i]->scheduled_at);
    }

    expect(array_map(static fn (WebhookDelivery $d): int => $d->attempt_number, $deliveries))->toBe([1, 2, 3, 4, 5, 6, 7, 8])
        ->and($gaps)->toBe([5, 300, 1800, 7200, 18000, 36000, 36000])
        ->and(array_map(static fn (WebhookDelivery $d): string => $d->status->value, $deliveries))
        ->toBe([...array_fill(0, 7, 'failed'), 'abandoned'])
        ->and($deliveries[0]->next_retry_at?->equalTo($deliveries[1]->scheduled_at))->toBeTrue()
        ->and($deliveries[7]->next_retry_at)->toBeNull()
        ->and($deliveries[0]->scheduled_at->diffInHours($deliveries[7]->scheduled_at))->toBeGreaterThanOrEqual(27.0);

    // The same frozen body and webhook-id in every attempt (plan 15.3).
    $sent = collect(WebhookTestHelpers::sentRequests());
    expect($sent)->toHaveCount(8)
        ->and($sent->map(static fn (Request $request): string => $request->body())->unique()->count())->toBe(1)
        ->and($sent->map(static fn (Request $request): string => WebhookTestHelpers::header($request, 'webhook-id'))->unique()->count())->toBe(1)
        ->and(WebhookTestHelpers::freshEndpoint($endpoint)->status)->toBe(WebhookEndpointStatus::Enabled);
});

it('treats a redirect as a failure and never follows it', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.example/'])]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$first, $retry] = WebhookTestHelpers::deliveries($tenant);

    expect($first->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($first->response_status)->toBe(302)
        ->and($first->error)->toBe(WebhookDeliveryError::HttpStatus)
        ->and($retry->attempt_number)->toBe(2)
        ->and($retry->status)->toBe(WebhookDeliveryStatus::Pending);
    Http::assertSentCount(1);
});

it('records a timeout and retries it', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds')]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$first, $retry] = WebhookTestHelpers::deliveries($tenant);

    expect($first->error)->toBe(WebhookDeliveryError::Timeout)
        ->and($first->response_status)->toBeNull()
        ->and($first->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($retry->status)->toBe(WebhookDeliveryStatus::Pending);
});

it('checks the destination again at delivery time and never retries a blocked one', function (): void {
    Http::fake();
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    // DNS rebinding: the host now points to the metadata service.
    FakeHostResolver::install(['hooks.merchant.example' => ['169.254.169.254']]);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    $deliveries = WebhookTestHelpers::deliveries($tenant);

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]->status)->toBe(WebhookDeliveryStatus::Abandoned)
        ->and($deliveries[0]->error)->toBe(WebhookDeliveryError::BlockedDestination)
        ->and($deliveries[0]->isBlockedDestination())->toBeTrue()
        ->and($deliveries[0]->next_retry_at)->toBeNull()
        ->and(WebhookTestHelpers::freshEndpoint($endpoint)->failing_since)->not->toBeNull();
    Http::assertNothingSent();
});

it('sends to the normalized host, the one pinned to the validated addresses', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    // A URL stored before normalization: another case than the pinned host.
    WebhookTestHelpers::endpoint($tenant, attributes: ['url' => 'https://Hooks.MERCHANT.example/axispay?x=1']);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$request] = WebhookTestHelpers::sentRequests();

    expect($request->url())->toBe('https://hooks.merchant.example/axispay?x=1');
});

it('refuses a host with a trailing dot at delivery time (it would escape the address pin)', function (): void {
    Http::fake();
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant, attributes: ['url' => 'https://hooks.merchant.example./axispay']);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$delivery] = WebhookTestHelpers::deliveries($tenant);

    expect($delivery->error)->toBe(WebhookDeliveryError::BlockedDestination);
    Http::assertNothingSent();
});

it('counts the DNS lookup against the 10-second budget and reports a timeout', function (): void {
    Http::fake();
    config(['axispay.webhooks.timeout_seconds' => 1]);
    FakeHostResolver::install()->lookupSeconds = 1.05;
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$first, $retry] = WebhookTestHelpers::deliveries($tenant);

    expect($first->error)->toBe(WebhookDeliveryError::Timeout)
        ->and($retry->status)->toBe(WebhookDeliveryStatus::Pending);
    Http::assertNothingSent();
});

it('disables an endpoint after 5 days of continuous failures and notifies', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');
    Http::fake(['*' => Http::response('down', 503)]);
    $tenant = activeTenant();
    $owner = tenantUser($tenant);
    $endpoint = WebhookTestHelpers::endpoint($tenant, attributes: ['failing_since' => now()->subDays(5)->subMinute()]);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

    $fresh = WebhookTestHelpers::freshEndpoint($endpoint);
    expect($fresh->status)->toBe(WebhookEndpointStatus::DisabledByFailures)
        ->and($fresh->disabled_at)->not->toBeNull()
        ->and(WebhookTestHelpers::deliveries($tenant))->toHaveCount(1)
        ->and(WebhookTestHelpers::deliveries($tenant)[0]->status)->toBe(WebhookDeliveryStatus::Abandoned)
        ->and(WebhookTestHelpers::in($tenant, false, static fn () => AuditLog::query()->where('action', AuditAction::WebhookEndpointDisabledByFailures->value)->count()))->toBe(1);

    Notification::assertSentTo($owner, WebhookEndpointNotification::class, static fn (WebhookEndpointNotification $n): bool => $n->host === 'hooks.merchant.example');

    // A disabled endpoint receives nothing more.
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    expect(WebhookTestHelpers::deliveries($tenant))->toHaveCount(1);
    Http::assertSentCount(1);
});

it('keeps an endpoint failing for less than 5 days enabled, and a success clears the streak', function (): void {
    Http::fakeSequence()->push('down', 500)->push('ok', 200);
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant, attributes: ['failing_since' => now()->subDays(4)]);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    expect(WebhookTestHelpers::freshEndpoint($endpoint)->status)->toBe(WebhookEndpointStatus::Enabled)
        ->and(WebhookTestHelpers::freshEndpoint($endpoint)->failing_since)->not->toBeNull();

    expect(sendNextAttempt($endpoint)?->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and(WebhookTestHelpers::freshEndpoint($endpoint)->failing_since)->toBeNull();
});

it('sends both signatures during a secret rotation', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    $signer = app(WebhookSigner::class);
    [$new, $old] = [$signer->generateSecret(), $signer->generateSecret()];
    WebhookTestHelpers::endpoint($tenant, attributes: ['secret' => $new, 'previous_secret' => $old, 'previous_secret_expires_at' => now()->addHours(3)]);

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

    Http::assertSent(static function (Request $request) use ($signer, $new, $old): bool {
        $header = WebhookTestHelpers::header($request, 'webhook-signature');
        $id = WebhookTestHelpers::header($request, 'webhook-id');
        $timestamp = (int) WebhookTestHelpers::header($request, 'webhook-timestamp');

        return count(explode(' ', $header)) === 2
            && str_starts_with($header, 'v1,')
            && $signer->verify($new, $id, $timestamp, $request->body(), $header, $timestamp)
            && $signer->verify($old, $id, $timestamp, $request->body(), $header, $timestamp);
    });
});

it('drops a pending attempt when its endpoint was disabled meanwhile', function (): void {
    Http::fake(['*' => Http::response('down', 500)]);
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    WebhookTestHelpers::in($tenant, false, static fn () => WebhookEndpoint::query()->findOrFail($endpoint->id)->forceFill(['status' => WebhookEndpointStatus::DisabledByUser])->save());

    $dropped = sendNextAttempt($endpoint);

    expect($dropped?->status)->toBe(WebhookDeliveryStatus::Abandoned)
        ->and($dropped?->error)->toBe(WebhookDeliveryError::EndpointDisabled);
    Http::assertSentCount(1);
});

it('never sends the same attempt twice (the send lease)', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$delivery] = WebhookTestHelpers::deliveries($tenant);

    // A duplicated job for a finished attempt.
    WebhookTestHelpers::in($tenant, false, static fn () => app(DeliverWebhook::class)->handle($delivery->id));

    Http::assertSentCount(1);
});

it('sends an attempt whose job runs a moment early (clock skew)', function (): void {
    Http::fakeSequence()->push('down', 500)->push('ok', 200);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [, $retry] = WebhookTestHelpers::deliveries($tenant);

    Carbon::setTestNow($retry->scheduled_at->subSeconds(DeliverWebhook::EARLY_TOLERANCE_SECONDS));
    $sent = WebhookTestHelpers::in($tenant, false, static fn () => app(DeliverWebhook::class)->handle($retry->id));

    expect($sent?->status)->toBe(WebhookDeliveryStatus::Succeeded);
    Http::assertSentCount(2);
});

it('hands a job that runs too early back to the queue with the remaining delay', function (): void {
    Http::fakeSequence()->push('down', 500)->push('ok', 200);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [, $retry] = WebhookTestHelpers::deliveries($tenant);

    Carbon::setTestNow($retry->scheduled_at->subSeconds(30));
    $released = null;
    // A plain closure, not `fn`: an arrow function captures $released by
    // value, so the reference below would bind to its copy.
    $result = WebhookTestHelpers::in($tenant, false, static function () use ($retry, &$released) {
        return app(DeliverWebhook::class)->handle($retry->id, static function (int $seconds) use (&$released): void {
            $released = $seconds;
        });
    });

    expect($released)->toBe(30)
        ->and($result?->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($result?->queued_at?->equalTo(now()))->toBeTrue();
    Http::assertSentCount(1);
});

it('resends an event manually with the same webhook-id and body, as a single attempt', function (): void {
    Http::fakeSequence()->push('down', 500)->push('ok', 200);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$failed] = WebhookTestHelpers::deliveries($tenant);

    $manual = app(ResendWebhookDelivery::class)->handle($owner, $failed);

    expect($manual->trigger)->toBe(WebhookDeliveryTrigger::Manual)
        ->and($manual->attempt_number)->toBe(1)
        ->and($manual->webhook_event_id)->toBe($failed->webhook_event_id)
        ->and($manual->status)->toBe(WebhookDeliveryStatus::Succeeded);

    $sent = WebhookTestHelpers::sentRequests();
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->body())->toBe($sent[1]->body())
        ->and(WebhookTestHelpers::header($sent[0], 'webhook-id'))->toBe(WebhookTestHelpers::header($sent[1], 'webhook-id'))
        ->and(WebhookTestHelpers::in($tenant, false, static fn () => AuditLog::query()->where('action', AuditAction::WebhookDeliveryResent->value)->count()))->toBe(1);
});

it('refuses a manual resend to a disabled endpoint', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    [$delivery] = WebhookTestHelpers::deliveries($tenant);
    WebhookEndpoint::query()->findOrFail($endpoint->id)->forceFill(['status' => WebhookEndpointStatus::DisabledByUser])->save();

    $refused = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(ResendWebhookDelivery::class)->handle($owner, $delivery));

    expect($refused->reason)->toBe(WebhookEndpointRefusal::EndpointDisabled);
});

it('sends a signed ping synchronously and returns the result for the panel', function (): void {
    Http::fake(['*' => Http::response('pong', 200)]);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $endpoint = WebhookTestHelpers::endpoint($tenant);

    $result = app(SendTestWebhook::class)->handle($owner, $endpoint);

    expect($result->trigger)->toBe(WebhookDeliveryTrigger::Test)
        ->and($result->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($result->response_status)->toBe(200)
        ->and($result->response_body_excerpt)->toBe('pong')
        ->and($result->duration_ms)->toBeInt();

    Http::assertSent(static fn (Request $request): bool => (jsonArray($request->body())['type'] ?? null) === 'ping'
        && app(WebhookSigner::class)->verify(
            $endpoint->secret,
            WebhookTestHelpers::header($request, 'webhook-id'),
            (int) WebhookTestHelpers::header($request, 'webhook-timestamp'),
            $request->body(),
            WebhookTestHelpers::header($request, 'webhook-signature'),
            now()->getTimestamp(),
        ));
});

it('sends a ping to a disabled endpoint and never changes its health', function (): void {
    Http::fake(['*' => Http::response('down', 500)]);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $endpoint = WebhookTestHelpers::endpoint($tenant, attributes: ['status' => WebhookEndpointStatus::DisabledByUser]);

    $result = app(SendTestWebhook::class)->handle($owner, $endpoint);

    expect($result->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($result->response_status)->toBe(500)
        ->and($result->next_retry_at)->toBeNull()
        ->and(WebhookTestHelpers::freshEndpoint($endpoint)->failing_since)->toBeNull()
        ->and(WebhookTestHelpers::deliveries($tenant))->toHaveCount(1);
});

it('queues a far retry from the sweeper once it is due', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');
    Http::fake(['*' => Http::response('down', 500)]);
    config(['axispay.webhooks.retry_schedule_seconds' => [0, 300]]);
    $tenant = activeTenant();
    WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

    [, $retry] = WebhookTestHelpers::deliveries($tenant);
    expect($retry->queued_at)->toBeNull();

    Carbon::setTestNow('2026-10-06 12:04:59');
    artisanCommand('axispay:webhooks:sweep')->assertSuccessful();
    Http::assertSentCount(1);

    Carbon::setTestNow('2026-10-06 12:05:00');
    artisanCommand('axispay:webhooks:sweep')->assertSuccessful();
    Http::assertSentCount(2);
    expect(WebhookTestHelpers::deliveries($tenant)[1]->status)->toBe(WebhookDeliveryStatus::Abandoned);
});
