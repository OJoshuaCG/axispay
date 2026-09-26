<?php

declare(strict_types=1);

use App\Modules\PlatformAdmin\Filament\Resources\Tenants\Pages\ViewTenant;
use Livewire\Livewire;
use Tests\Support\GatewayTestHelpers;

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
