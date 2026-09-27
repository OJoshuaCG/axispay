<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\travelTo;

/**
 * Platform panel, tenant view (Phase 2, read-only): the tenant's gateway
 * connections with method, status, country and last sync; never secrets.
 */
it('shows the tenant gateway connections without any secret', function (): void {
    $tenant = activeTenant();
    $secret = GatewayTestHelpers::restrictedKey();
    $connection = GatewayTestHelpers::connection($tenant, state: static fn ($factory) => $factory->apiKey($secret)->state(['provider_account_id' => 'acct_Admin0001']));
    actingAsPlatformAdmin(platformAdmin());

    $html = Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertSee(__('gateways.admin.heading'))
        ->assertSee('acct_Admin0001')
        ->assertSee(__('gateways.method.api_key'))
        ->assertSee(__('gateways.status.active'))
        ->html();

    expect($html)->not->toContain($secret)
        ->and($html)->not->toContain($connection->credentials_secret ?? 'missing')
        ->and($html)->not->toContain($connection->provider_webhook_secret ?? 'missing')
        ->and($html)->not->toContain($connection->credentials_fingerprint ?? 'missing');
});

it('says when the tenant has no gateway connection', function (): void {
    actingAsPlatformAdmin(platformAdmin());

    Livewire::test(ViewTenant::class, ['record' => activeTenant()->getRouteKey()])
        ->assertSee(__('gateways.admin.empty'));
});

/*
| Last Stripe event per connection (ADR-0050), in UTC like the rest of the
| platform panel, with a warning badge when a connection that can charge has
| received no event in `silence_warning_days`.
*/

function adminViewStripeEvent(GatewayConnection $connection, CarbonImmutable $receivedAt): void
{
    app(TenantContext::class)->runAsTenant($connection->tenant_id, $connection->livemode, static function () use ($connection, $receivedAt): void {
        $row = new ProviderEvent;
        $row->forceFill([
            'provider' => GatewayProvider::Stripe,
            'provider_event_id' => 'evt_'.Ulid::generate(),
            'provider_account_id' => $connection->provider_account_id,
            'livemode' => $connection->livemode,
            'type' => 'account.updated',
            'payload' => '{}',
            'gateway_connection_id' => $connection->id,
            'status' => ProviderEventStatus::Processed,
            'received_at' => $receivedAt,
            'processed_at' => $receivedAt,
        ])->save();
    });
}

it('shows the last Stripe event of each connection, relative and absolute in UTC', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-27 12:00:00', 'UTC'));
    $tenant = activeTenant();
    $connection = GatewayTestHelpers::connection($tenant, state: static fn ($factory) => $factory->state(['connected_at' => now()->subDays(30)]));
    $receivedAt = CarbonImmutable::parse('2026-09-27 09:00:00', 'UTC');
    adminViewStripeEvent($connection, $receivedAt->subDay());
    adminViewStripeEvent($connection, $receivedAt);
    actingAsPlatformAdmin(platformAdmin());

    Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertSee(__('gateways.admin.last_event'))
        ->assertSee(__('gateways.admin.last_event_value', ['relative' => $receivedAt->diffForHumans(), 'date' => $receivedAt->isoFormat('lll')]))
        ->assertDontSee(__('gateways.admin.silent', ['days' => 7]));
});

it('flags a connection that can charge but received no Stripe event in the window', function (): void {
    $tenant = activeTenant();
    GatewayTestHelpers::connection($tenant, state: static fn ($factory) => $factory->state(['connected_at' => now()->subDays(30)]));
    actingAsPlatformAdmin(platformAdmin());

    Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertSee(__('gateways.admin.no_events'))
        ->assertSee(__('gateways.admin.silent', ['days' => 7]));
});

it('flags a connection whose last Stripe event is older than the window', function (): void {
    $tenant = activeTenant();
    $connection = GatewayTestHelpers::connection($tenant, state: static fn ($factory) => $factory->state(['connected_at' => now()->subDays(30)]));
    adminViewStripeEvent($connection, now()->toImmutable()->subDays(8));
    actingAsPlatformAdmin(platformAdmin());

    Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertSee(__('gateways.admin.silent', ['days' => 7]));
});

it('does not flag a connection that cannot charge or was connected inside the window', function (): void {
    $tenant = activeTenant();
    GatewayTestHelpers::connection($tenant, state: static fn ($factory) => $factory->onboarding());
    GatewayTestHelpers::connection($tenant, livemode: true, state: static fn ($factory) => $factory->state(['connected_at' => now()->subDays(2)]));
    actingAsPlatformAdmin(platformAdmin());

    Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertSee(__('gateways.admin.no_events'))
        ->assertDontSee(__('gateways.admin.silent', ['days' => 7]));
});

it('never shows the events of another tenant\'s connection', function (): void {
    $tenant = activeTenant();
    GatewayTestHelpers::connection($tenant, state: static fn ($factory) => $factory->state(['connected_at' => now()->subDays(30)]));
    $other = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->state(['connected_at' => now()->subDays(30)]));
    adminViewStripeEvent($other, now()->toImmutable()->subHour());
    actingAsPlatformAdmin(platformAdmin());

    Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertSee(__('gateways.admin.no_events'))
        ->assertSee(__('gateways.admin.silent', ['days' => 7]));
});
