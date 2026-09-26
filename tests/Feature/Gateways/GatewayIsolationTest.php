<?php

declare(strict_types=1);

use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Gateways\Models\GatewayConnection;
use Livewire\Livewire;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/**
 * Plan 6.6: a tenant never sees or reaches another tenant's gateway
 * connection (404, not 403), and test and live connections never mix.
 */
beforeEach(function (): void {
    startSession();
});

it('shows only the current tenant connection on the Stripe page', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    GatewayTestHelpers::connection($a, state: static fn ($factory) => $factory->state(['provider_account_id' => 'acct_TenantA0001']));
    GatewayTestHelpers::connection($b, state: static fn ($factory) => $factory->state(['provider_account_id' => 'acct_TenantB0001']));
    actingAsTenantUser(tenantUser($a));

    get(appUrl('/settings/stripe'))->assertOk()->assertSee('acct_TenantA0001')->assertDontSee('acct_TenantB0001');
    expect(GatewayConnection::query()->pluck('provider_account_id')->all())->toBe(['acct_TenantA0001']);
});

it('keeps test and live connections apart', function (): void {
    $tenant = activeTenant();
    GatewayTestHelpers::connection($tenant, livemode: true, state: static fn ($factory) => $factory->state(['provider_account_id' => 'acct_LiveOnly0001']));
    actingAsTenantUser(tenantUser($tenant), livemode: false);

    Livewire::test(StripeConnection::class)
        ->assertDontSee('acct_LiveOnly0001')
        ->assertSee(__('gateways.connect.onboarding.heading'));
});

it('answers 404 for another tenant connection on the onboarding return and refresh URLs', function (string $route): void {
    $foreign = GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->onboarding());
    actingAsTenantUser(tenantUser(activeTenant()));
    GatewayTestHelpers::reauthenticated();

    get(appUrl("/gateways/stripe/onboarding/{$foreign->id}/{$route}"))->assertNotFound();
    expect(stripeHttp()->requests)->toBe([]);
})->with(['return', 'refresh']);
