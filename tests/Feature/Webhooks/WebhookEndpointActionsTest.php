<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Webhooks\Actions\CreateWebhookEndpoint;
use App\Modules\Webhooks\Actions\DeleteWebhookEndpoint;
use App\Modules\Webhooks\Actions\DisableWebhookEndpoint;
use App\Modules\Webhooks\Actions\EnableWebhookEndpoint;
use App\Modules\Webhooks\Actions\RevealWebhookEndpointSecret;
use App\Modules\Webhooks\Actions\RotateWebhookEndpointSecret;
use App\Modules\Webhooks\Actions\UpdateWebhookEndpoint;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\UnsafeDestinationReason;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Notifications\WebhookEndpointNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeHostResolver;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\WebhookTestHelpers;

/**
 * Plan 15.1 / 15.5 / 17.3: the actions the panel calls to manage webhook
 * endpoints (`webhooks:manage`, re-authentication, SSRF check, secrets).
 */
beforeEach(function (): void {
    FakeHostResolver::install();
    Notification::fake();
});

/**
 * @param  list<string>  $events
 */
function endpointData(string $url = WebhookTestHelpers::URL, array $events = ['*'], ?string $description = 'Store'): WebhookEndpointData
{
    return new WebhookEndpointData($url, $description, $events);
}

it('stores the normalized URL and refuses a host with a trailing dot', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $issued = app(CreateWebhookEndpoint::class)->handle($owner, endpointData('https://HOOKS.Merchant.example/axispay#section'));

    expect($issued->endpoint->url)->toBe('https://hooks.merchant.example/axispay');

    try {
        app(CreateWebhookEndpoint::class)->handle($owner, endpointData('https://hooks.merchant.example./axispay'));
        $reason = null;
    } catch (UnsafeDestinationException $e) {
        $reason = $e->reason;
    }

    expect($reason)->toBe(UnsafeDestinationReason::InvalidUrl)
        ->and(WebhookEndpoint::query()->count())->toBe(1);
});

it('creates an endpoint, returns its secret once and stores it encrypted', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $issued = app(CreateWebhookEndpoint::class)->handle($owner, endpointData(events: ['payment.succeeded', 'payment_link.paid']));

    expect($issued->secret)->toMatch('/^whsec_[A-Za-z0-9+\/]{43}=$/')
        ->and($issued->endpoint->status)->toBe(WebhookEndpointStatus::Enabled)
        ->and($issued->endpoint->livemode)->toBeFalse()
        // Catalog order, whatever order the form sent.
        ->and($issued->endpoint->enabled_events)->toBe(['payment_link.paid', 'payment.succeeded'])
        ->and($issued->endpoint->toArray())->not->toHaveKey('secret');

    $raw = WebhookEndpoint::query()->toBase()->where('id', $issued->endpoint->id)->value('secret');
    expect(is_string($raw) && $raw !== '' && ! str_contains($raw, $issued->secret))->toBeTrue()
        ->and(WebhookEndpoint::query()->sole()->secret)->toBe($issued->secret);

    $audit = AuditLog::query()->where('action', AuditAction::WebhookEndpointCreated->value)->sole();
    expect((string) json_encode($audit->changes))->not->toContain($issued->secret)
        ->and($audit->changes['host'] ?? null)->toBe('hooks.merchant.example');

    Notification::assertSentTo($owner, WebhookEndpointNotification::class);
});

it('allows at most 5 endpoints per mode', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    foreach (range(1, 5) as $i) {
        WebhookTestHelpers::endpoint($tenant);
    }

    $refused = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(CreateWebhookEndpoint::class)->handle($owner, endpointData()));
    expect($refused->reason)->toBe(WebhookEndpointRefusal::TooManyEndpoints);

    // The other mode has its own limit.
    actingAsTenantUser($owner, livemode: true);
    GatewayTestHelpers::reauthenticated();
    expect(app(CreateWebhookEndpoint::class)->handle($owner, endpointData())->endpoint->livemode)->toBeTrue();
});

it('refuses a URL the SSRF protection blocks, at registration', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    FakeHostResolver::install(['internal.merchant.example' => ['10.0.0.8']]);

    $refused = thrownBy(UnsafeDestinationException::class, fn () => app(CreateWebhookEndpoint::class)->handle($owner, endpointData('https://internal.merchant.example/hook')));
    expect($refused->reason)->toBe(UnsafeDestinationReason::ForbiddenAddress);

    $refused = thrownBy(UnsafeDestinationException::class, fn () => app(CreateWebhookEndpoint::class)->handle($owner, endpointData('http://hooks.merchant.example/hook')));
    expect($refused->reason)->toBe(UnsafeDestinationReason::SchemeNotAllowed)
        ->and(WebhookEndpoint::query()->count())->toBe(0);
});

it('refuses an empty or unknown subscription and ping', function (string $events, WebhookEndpointRefusal $reason): void {
    $owner = actingAsTenantUser(tenantUser(activeTenant()));
    GatewayTestHelpers::reauthenticated();

    $refused = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(CreateWebhookEndpoint::class)->handle($owner, endpointData(events: $events === '' ? [] : [$events])));
    expect($refused->reason)->toBe($reason);
})->with([
    'none' => ['', WebhookEndpointRefusal::NoEvents],
    'unknown' => ['payment.teleported', WebhookEndpointRefusal::UnknownEvent],
    'ping' => ['ping', WebhookEndpointRefusal::UnknownEvent],
]);

it('requires re-authentication to create, edit, rotate, reveal, enable and delete', function (Closure $call): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $endpoint = WebhookTestHelpers::endpoint($tenant, attributes: ['status' => WebhookEndpointStatus::DisabledByUser]);

    expect(fn () => $call($owner, $endpoint))->toThrow(ReauthenticationRequiredException::class);
})->with([
    'create' => [fn (User $user) => app(CreateWebhookEndpoint::class)->handle($user, endpointData())],
    'update' => [fn (User $user, WebhookEndpoint $endpoint) => app(UpdateWebhookEndpoint::class)->handle($user, $endpoint, endpointData(description: 'Changed'))],
    'rotate' => [fn (User $user, WebhookEndpoint $endpoint) => app(RotateWebhookEndpointSecret::class)->handle($user, $endpoint)],
    'reveal' => [fn (User $user, WebhookEndpoint $endpoint) => app(RevealWebhookEndpointSecret::class)->handle($user, $endpoint)],
    'enable' => [fn (User $user, WebhookEndpoint $endpoint) => app(EnableWebhookEndpoint::class)->handle($user, $endpoint)],
    'delete' => [fn (User $user, WebhookEndpoint $endpoint) => app(DeleteWebhookEndpoint::class)->handle($user, $endpoint)],
]);

it('requires webhooks:manage (a permission, not a role)', function (): void {
    $tenant = activeTenant();
    $viewer = actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    GatewayTestHelpers::reauthenticated();
    $endpoint = WebhookTestHelpers::endpoint($tenant);

    expect(fn () => app(CreateWebhookEndpoint::class)->handle($viewer, endpointData()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DisableWebhookEndpoint::class)->handle($viewer, $endpoint))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RevealWebhookEndpointSecret::class)->handle($viewer, $endpoint))->toThrow(AuthorizationException::class);

    $manager = actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));
    GatewayTestHelpers::reauthenticated();
    expect(app(RevealWebhookEndpointSecret::class)->handle($manager, $endpoint))->toBe($endpoint->secret);
});

it('keeps a suspended tenant read-only but lets it disable and delete', function (): void {
    $tenant = activeTenant(TenantStatus::Suspended);
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $endpoint = WebhookTestHelpers::endpoint($tenant);

    $refused = thrownBy(WebhookEndpointNotAllowedException::class, fn () => app(CreateWebhookEndpoint::class)->handle($owner, endpointData()));
    expect($refused->reason)->toBe(WebhookEndpointRefusal::TenantReadOnly)
        ->and(app(DisableWebhookEndpoint::class)->handle($owner, $endpoint)->status)->toBe(WebhookEndpointStatus::DisabledByUser);

    app(DeleteWebhookEndpoint::class)->handle($owner, $endpoint);
    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('updates the URL, description and events, checking a new URL again', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    $secret = $endpoint->secret;

    $updated = app(UpdateWebhookEndpoint::class)->handle($owner, $endpoint, endpointData('https://new.merchant.example/hook', ['payment.failed'], 'New'));

    expect($updated->url)->toBe('https://new.merchant.example/hook')
        ->and($updated->description)->toBe('New')
        ->and($updated->enabled_events)->toBe(['payment.failed'])
        ->and(WebhookTestHelpers::freshEndpoint($endpoint)->secret)->toBe($secret);
    Notification::assertSentTo($owner, WebhookEndpointNotification::class);

    FakeHostResolver::install(['evil.merchant.example' => ['127.0.0.1']]);
    expect(fn () => app(UpdateWebhookEndpoint::class)->handle($owner, $updated, endpointData('https://evil.merchant.example/hook')))
        ->toThrow(UnsafeDestinationException::class);
});

it('rotates the secret and keeps the previous one signing for 24 hours', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    $old = $endpoint->secret;

    $issued = app(RotateWebhookEndpointSecret::class)->handle($owner, $endpoint);
    $fresh = WebhookTestHelpers::freshEndpoint($endpoint);

    expect($issued->secret)->not->toBe($old)
        ->and($fresh->secret)->toBe($issued->secret)
        ->and($fresh->previous_secret)->toBe($old)
        ->and($fresh->signingSecrets())->toBe([$issued->secret, $old]);

    Carbon::setTestNow('2026-10-07 11:59:59');
    expect(WebhookTestHelpers::freshEndpoint($endpoint)->signingSecrets())->toBe([$issued->secret, $old]);

    Carbon::setTestNow('2026-10-07 12:00:00');
    expect(WebhookTestHelpers::freshEndpoint($endpoint)->signingSecrets())->toBe([$issued->secret]);

    $audit = AuditLog::query()->where('action', AuditAction::WebhookEndpointSecretRotated->value)->sole();
    $changes = (string) json_encode($audit->changes);
    expect(str_contains($changes, $issued->secret) || str_contains($changes, $old))->toBeFalse();
});

it('disables and enables an endpoint, and enabling starts the failure streak over', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $endpoint = WebhookTestHelpers::endpoint($tenant, attributes: [
        'status' => WebhookEndpointStatus::DisabledByFailures,
        'failing_since' => now()->subDays(6),
        'disabled_at' => now(),
    ]);

    $enabled = app(EnableWebhookEndpoint::class)->handle($owner, $endpoint);
    expect($enabled->status)->toBe(WebhookEndpointStatus::Enabled)
        ->and($enabled->failing_since)->toBeNull()
        ->and($enabled->disabled_at)->toBeNull();

    $disabled = app(DisableWebhookEndpoint::class)->handle($owner, $enabled);
    expect($disabled->status)->toBe(WebhookEndpointStatus::DisabledByUser)
        ->and($disabled->disabled_at)->not->toBeNull();
});

it('deletes an endpoint with its delivery log and keeps the events', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);
    expect(WebhookTestHelpers::deliveries($tenant))->toHaveCount(1);

    app(DeleteWebhookEndpoint::class)->handle($owner, $endpoint);

    expect(WebhookTestHelpers::deliveries($tenant))->toBe([])
        ->and(WebhookTestHelpers::events($tenant))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', AuditAction::WebhookEndpointDeleted->value)->count())->toBe(1);
});
