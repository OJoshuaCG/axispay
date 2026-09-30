<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Enums\WebhookEndpointStatus;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\FakeHostResolver;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/**
 * Settings → Webhooks in the tenant panel (plan 15.1): `webhooks:manage`,
 * the secret shown once, "Send test event", the delivery log and resend.
 * The actions themselves are covered by WebhookEndpointActionsTest.
 */
beforeEach(function (): void {
    startSession();
    FakeHostResolver::install();
    Notification::fake();
});

it('is only reachable with webhooks:manage', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);

    foreach ([SystemRole::Viewer, SystemRole::Finance, SystemRole::LinkCreator] as $role) {
        actingAsTenantUser(tenantUser($tenant, [$role]));
        get(appUrl('/settings/webhooks'))->assertForbidden();
        get(appUrl('/settings/webhooks/'.$endpoint->id))->assertForbidden();
        expect(WebhookEndpointResource::canViewAny())->toBeFalse();
    }

    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));
    get(appUrl('/settings/webhooks'))->assertOk()->assertSee(__('webhooks.plural'), false);
    get(appUrl('/settings/webhooks/'.$endpoint->id))->assertOk();
});

it('lists the endpoints of the current mode with their events, status and failing badge', function (string $locale): void {
    $tenant = activeTenant();

    app()->setLocale($locale);
    $all = WebhookTestHelpers::endpoint($tenant, attributes: ['description' => 'Order system']);
    $some = WebhookTestHelpers::endpoint($tenant, attributes: [
        'url' => 'https://erp.merchant.example/hooks',
        'enabled_events' => ['payment.succeeded'],
        'failing_since' => now()->subDays(2),
    ]);
    $disabled = WebhookTestHelpers::endpoint($tenant, attributes: ['url' => 'https://old.merchant.example/hooks', 'status' => WebhookEndpointStatus::DisabledByFailures]);
    $live = WebhookTestHelpers::endpoint($tenant, livemode: true);
    actingAsTenantUser(tenantUser($tenant), livemode: false);

    Livewire::test(ListWebhookEndpoints::class)
        ->assertCanSeeTableRecords([$all, $some, $disabled])
        ->assertCanNotSeeTableRecords([$live])
        ->assertSee('Order system')
        ->assertSee(__('webhooks.all_events'))
        ->assertSee('payment.succeeded')
        ->assertSee(WebhookEndpointStatus::DisabledByFailures->label())
        ->assertSee(WebhookEndpointResource::failingLine($some) ?? '')
        ->assertSee(__('webhooks.page.subheading.test', ['max' => 5]));
})->with(['en', 'es']);

it('says a new endpoint has no deliveries yet instead of claiming it is healthy', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    actingAsTenantUser(tenantUser($tenant));

    $health = static fn (): string => WebhookTestHelpers::in($tenant, false, static fn (): string => WebhookEndpointResource::healthPlaceholder($endpoint));

    expect($health())->toBe(__('webhooks.fields.no_deliveries'));
    Livewire::test(ListWebhookEndpoints::class)
        ->assertSee(__('webhooks.fields.no_deliveries'))
        ->assertDontSee(__('webhooks.fields.healthy'));

    WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

    expect($health())->toBe(__('webhooks.fields.healthy'));
});

it('labels each event of the form by what it means, with its code below', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $component = Livewire::test(ListWebhookEndpoints::class);
    $component->mountAction('create');
    $component->fillForm(['all_events' => false]);
    $component->assertMountedActionModalSee(WebhookEventType::PaymentSucceeded->description());
    $component->assertMountedActionModalSee('payment.succeeded');
});

it('creates an endpoint and shows its secret once, never in the page state', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $component = Livewire::test(ListWebhookEndpoints::class);
    $component->callAction('create', data: [
        'url' => WebhookTestHelpers::URL,
        'description' => 'Store',
        'all_events' => false,
        'events' => ['payment.succeeded', 'payment_link.paid'],
    ]);
    $component->assertHasNoActionErrors();
    $component->assertActionMounted('showIssuedSecret');

    $modal = $component->getMountedActionModalHtml();
    assert(is_string($modal));
    preg_match('/whsec_[A-Za-z0-9+\/]{43}=/', $modal, $match);
    $secret = $match[0] ?? '';

    $endpoint = WebhookEndpoint::query()->sole();
    expect($secret)->toBe($endpoint->secret)
        ->and($endpoint->enabled_events)->toBe(['payment_link.paid', 'payment.succeeded'])
        ->and($endpoint->description)->toBe('Store')
        ->and((string) json_encode($component->__get('snapshot')))->not->toContain($secret);

    // "I have copied the secret" erases it from the page.
    $component->callMountedAction();
    $component->assertActionNotMounted('showIssuedSecret');
    expect($component->html())->not->toContain($secret);
});

it('subscribes to every event with the "send every event" switch', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('create', data: ['url' => WebhookTestHelpers::URL, 'all_events' => true])
        ->assertHasNoActionErrors();

    expect(WebhookEndpoint::query()->sole()->enabled_events)->toBe(['*']);
});

it('asks for the password again before creating an endpoint', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('create', data: ['url' => WebhookTestHelpers::URL, 'all_events' => true, 'current_password' => 'wrong-password'])
        ->assertHasActionErrors(['current_password']);

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('shows a URL refused by the SSRF protection on the URL field', function (): void {
    $tenant = activeTenant();
    FakeHostResolver::install(['internal.merchant.example' => ['10.0.0.5']]);
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('create', data: ['url' => 'https://internal.merchant.example/hooks', 'all_events' => true])
        ->assertHasActionErrors(['url']);

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('requires at least one event when not sending every event', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    Livewire::test(ListWebhookEndpoints::class)
        ->callAction('create', data: ['url' => WebhookTestHelpers::URL, 'all_events' => false, 'events' => []])
        ->assertHasActionErrors(['events' => 'required']);
});

it('sends a test event and shows the HTTP status, the latency and the answer', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    Http::fake(['*' => Http::response('{"received":true}', 200, ['Content-Type' => 'application/json'])]);
    actingAsTenantUser(tenantUser($tenant));

    $component = Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()]);
    $component->callAction('sendTest')->assertHasNoActionErrors();
    $component->assertActionMounted('showTestResult');
    $component->assertMountedActionModalSee(__('webhooks.test_result.delivered'));
    $component->assertMountedActionModalSee('200');
    $component->assertMountedActionModalSee('{"received":true}');

    Http::assertSent(static fn (Request $request): bool => $request->url() === WebhookTestHelpers::URL
        && str_contains($request->body(), '"type":"ping"')
        && WebhookTestHelpers::header($request, 'webhook-signature') !== '');

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->trigger)->toBe(WebhookDeliveryTrigger::Test)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded);
});

it('shows a failed test event with the error', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    Http::fake(['*' => Http::response('Internal error', 500)]);
    actingAsTenantUser(tenantUser($tenant));

    $component = Livewire::test(ListWebhookEndpoints::class)
        ->callTableAction('sendTest', $endpoint)
        ->assertHasNoTableActionErrors()
        ->assertActionMounted('showTestResult');
    $component->assertMountedActionModalSee(__('webhooks.test_result.not_delivered'));
    $component->assertMountedActionModalSee('500');
    $component->assertMountedActionModalSee(__('webhooks.delivery_error.http_status'));
    $component->assertMountedActionModalSee(__('webhooks.test_result.next_step.http_status'));

    // A test never changes the endpoint's health.
    expect(WebhookTestHelpers::freshEndpoint($endpoint)->failing_since)->toBeNull();
});

it('rotates and reveals the secret after re-authentication', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    $old = $endpoint->secret;
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    $component = Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()]);
    $component->callAction('rotateSecret')->assertHasNoActionErrors()->assertActionMounted('showIssuedSecret');

    $fresh = WebhookTestHelpers::freshEndpoint($endpoint);
    expect($fresh->secret)->not->toBe($old)
        ->and($fresh->previous_secret)->toBe($old);
    $component->assertMountedActionModalSee($fresh->secret);
    $component->callMountedAction();

    $component = Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])
        ->callAction('revealSecret')
        ->assertHasNoActionErrors()
        ->assertActionMounted('showIssuedSecret');
    $component->assertMountedActionModalSee($fresh->secret);
});

it('edits, disables, enables and deletes an endpoint', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    actingAsTenantUser(tenantUser($tenant));
    GatewayTestHelpers::reauthenticated();

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])
        ->callAction('edit', data: ['url' => WebhookTestHelpers::URL, 'description' => 'ERP', 'all_events' => false, 'events' => ['payment.failed']])
        ->assertHasNoActionErrors();
    expect(WebhookTestHelpers::freshEndpoint($endpoint)->enabled_events)->toBe(['payment.failed'])
        ->and(WebhookTestHelpers::freshEndpoint($endpoint)->description)->toBe('ERP');

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])
        ->assertActionHidden('enable')
        ->callAction('disable')
        ->assertHasNoActionErrors();
    expect(WebhookTestHelpers::freshEndpoint($endpoint)->status)->toBe(WebhookEndpointStatus::DisabledByUser);

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])
        ->assertActionHidden('disable')
        ->callAction('enable')
        ->assertHasNoActionErrors();
    expect(WebhookTestHelpers::freshEndpoint($endpoint)->status)->toBe(WebhookEndpointStatus::Enabled);

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $endpoint->getRouteKey()])
        ->callAction('delete')
        ->assertHasNoActionErrors()
        ->assertRedirect(WebhookEndpointResource::getUrl('index'));
    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('lists the deliveries of the endpoint and resends one', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    [$event, $failed] = WebhookTestHelpers::in($tenant, false, static function () use ($endpoint): array {
        $event = new WebhookEvent;
        $event->forceFill([
            'livemode' => false,
            'type' => 'payment.succeeded',
            'payload' => '{"id":"evt_x","type":"payment.succeeded"}',
        ])->save();

        $failed = new WebhookDelivery;
        $failed->forceFill([
            'livemode' => false,
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'trigger' => WebhookDeliveryTrigger::Automatic,
            'attempt_number' => 8,
            'status' => WebhookDeliveryStatus::Abandoned,
            'scheduled_at' => now(),
            'response_status' => 503,
            'duration_ms' => 120,
            'error' => 'http_status',
        ])->save();

        return [$event, $failed];
    });
    Http::fake(['*' => Http::response('', 200)]);
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(DeliveriesRelationManager::class, ['ownerRecord' => $endpoint, 'pageClass' => ViewWebhookEndpoint::class])
        ->assertCanSeeTableRecords([$failed])
        ->assertSee('payment.succeeded')
        ->assertSee(WebhookDeliveryStatus::Abandoned->label())
        ->filterTable('status', WebhookDeliveryStatus::Succeeded->value)
        ->assertCanNotSeeTableRecords([$failed])
        ->resetTableFilters()
        ->callTableAction('resend', $failed)
        ->assertHasNoTableActionErrors();

    $resent = WebhookTestHelpers::in($tenant, false, static fn () => WebhookDelivery::query()->where('trigger', WebhookDeliveryTrigger::Manual->value)->sole());
    expect($resent->webhook_event_id)->toBe($event->id)
        ->and($resent->attempt_number)->toBe(1);
});

it('hides the resend of a test delivery and of a pending one', function (): void {
    $tenant = activeTenant();
    $endpoint = WebhookTestHelpers::endpoint($tenant);
    $pending = WebhookTestHelpers::in($tenant, false, static function () use ($endpoint): WebhookDelivery {
        $event = new WebhookEvent;
        $event->forceFill(['livemode' => false, 'type' => 'ping', 'payload' => '{}'])->save();

        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'livemode' => false,
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'trigger' => WebhookDeliveryTrigger::Test,
            'attempt_number' => 1,
            'status' => WebhookDeliveryStatus::Succeeded,
            'scheduled_at' => now(),
        ])->save();

        return $delivery;
    });
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(DeliveriesRelationManager::class, ['ownerRecord' => $endpoint, 'pageClass' => ViewWebhookEndpoint::class])
        ->assertTableActionHidden('resend', $pending)
        ->assertTableActionVisible('details', $pending);
});
