<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Actions\StartPlatformOnboarding;
use App\Modules\Gateways\Enums\ConnectionError;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionNotice;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Exceptions\GatewayConnectionException;
use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Notifications\GatewayConnectionNotification;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeFixtures;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/**
 * Plan 12.3.1 (2A) and the Phase 2 acceptance criteria: a tenant creates its
 * connected account (Standard-equivalent controller properties, MX), goes
 * through hosted onboarding and becomes `active` once Stripe enables
 * charges; the tenant leaves pending_onboarding automatically (plan 21.3).
 */
beforeEach(function (): void {
    startSession();
    Notification::fake();

    stripeHttp()
        ->on('post', '/v1/accounts', static fn (array $request): array => [200, StripeFixtures::account('acct_Onboard0001', chargesEnabled: false)])
        ->on('post', '/v1/account_links', ['object' => 'account_link', 'url' => 'https://connect.stripe.com/setup/s/fake-link', 'created' => 1790000000, 'expires_at' => 1790000300]);
});

function onboardingOwner(TenantStatus $status = TenantStatus::PendingOnboarding): User
{
    return actingAsTenantUser(tenantUser(Tenant::factory()->status($status)->create()));
}

it('creates a Standard-equivalent MX account and returns a hosted onboarding link', function (): void {
    $owner = onboardingOwner();
    GatewayTestHelpers::reauthenticated();

    $url = app(StartPlatformOnboarding::class)->handle($owner, 'MX');

    $connection = GatewayConnection::query()->current()->firstOrFail();
    $create = stripeHttp()->requestsTo('post', '/v1/accounts')[0];
    $link = stripeHttp()->requestsTo('post', '/v1/account_links')[0];

    expect($url)->toBe('https://connect.stripe.com/setup/s/fake-link')
        ->and($connection->connection_method)->toBe(ConnectionMethod::PlatformOnboarding)
        ->and($connection->status)->toBe(ConnectionStatus::Onboarding)
        ->and($connection->provider_account_id)->toBe('acct_Onboard0001')
        ->and($connection->livemode)->toBeFalse()
        ->and($create['params'])->toMatchArray([
            'country' => 'MX',
            'controller' => [
                'fees' => ['payer' => 'account'],
                'losses' => ['payments' => 'stripe'],
                'requirement_collection' => 'stripe',
                'stripe_dashboard' => ['type' => 'full'],
            ],
        ])
        ->and($create['headers']['idempotency-key'])->toBe('axispay-account-'.$connection->id)
        ->and($create['headers'])->not->toHaveKey('stripe-account')
        ->and($link['params'])->toMatchArray([
            'account' => 'acct_Onboard0001',
            'type' => 'account_onboarding',
            'return_url' => route('gateways.stripe.onboarding.return', ['connection' => $connection->id]),
            'refresh_url' => route('gateways.stripe.onboarding.refresh', ['connection' => $connection->id]),
        ])
        ->and($link['headers']['idempotency-key'])->toStartWith('axispay-account-link-')
        ->and(AuditLog::query()->where('action', AuditAction::GatewayOnboardingStarted->value)->count())->toBe(1);
});

it('continues the same connection and account on a second attempt', function (): void {
    $owner = onboardingOwner();
    GatewayTestHelpers::reauthenticated();

    app(StartPlatformOnboarding::class)->handle($owner, 'MX');
    app(StartPlatformOnboarding::class)->handle($owner, 'MX');

    expect(GatewayConnection::query()->count())->toBe(1)
        ->and(stripeHttp()->requestsTo('post', '/v1/accounts'))->toHaveCount(1)
        ->and(stripeHttp()->requestsTo('post', '/v1/account_links'))->toHaveCount(2);
});

it('retries the account creation with the same idempotency key after a failure', function (): void {
    $owner = onboardingOwner();
    GatewayTestHelpers::reauthenticated();
    stripeHttp()->error('post', '/v1/accounts', 400, 'invalid_request_error', 'parameter_invalid_empty');

    expect(fn () => app(StartPlatformOnboarding::class)->handle($owner, 'MX'))->toThrow(GatewayConnectionException::class);

    stripeHttp()->on('post', '/v1/accounts', StripeFixtures::account('acct_Onboard0001', chargesEnabled: false));
    app(StartPlatformOnboarding::class)->handle($owner, 'MX');

    $keys = array_map(static fn (array $request): string => $request['headers']['idempotency-key'], stripeHttp()->requestsTo('post', '/v1/accounts'));

    expect(array_unique($keys))->toHaveCount(1)
        ->and(GatewayConnection::query()->count())->toBe(1);
});

it('requires re-authentication, gateway:manage, an allowed country and the method enabled', function (): void {
    $owner = onboardingOwner();

    expect(fn () => app(StartPlatformOnboarding::class)->handle($owner, 'MX'))->toThrow(ReauthenticationRequiredException::class);

    GatewayTestHelpers::reauthenticated();
    expect(thrownBy(GatewayConnectionException::class, fn () => app(StartPlatformOnboarding::class)->handle($owner, 'US'))->error)
        ->toBe(ConnectionError::CountryNotAllowed);

    config(['axispay.gateways.stripe.connection_methods.platform_onboarding' => false]);
    expect(thrownBy(GatewayConnectionException::class, fn () => app(StartPlatformOnboarding::class)->handle($owner, 'MX'))->error)
        ->toBe(ConnectionError::MethodDisabled);

    $viewer = actingAsTenantUser(tenantUser(tenantOf($owner), [SystemRole::Viewer]));
    expect(fn () => app(StartPlatformOnboarding::class)->handle($viewer, 'MX'))->toThrow(AuthorizationException::class);
    expect(stripeHttp()->requests)->toBe([]);
});

it('accepts other connected-account countries only when configured', function (): void {
    config(['axispay.gateways.stripe.allowed_countries' => ['MX', 'US']]);
    $owner = onboardingOwner();
    GatewayTestHelpers::reauthenticated();

    app(StartPlatformOnboarding::class)->handle($owner, 'us');

    expect(stripeHttp()->requestsTo('post', '/v1/accounts')[0]['params']['country'])->toBe('US');
});

it('syncs the account on return and activates the connection and the tenant', function (): void {
    $owner = onboardingOwner();
    $connection = GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->onboarding()->state(['provider_account_id' => 'acct_Onboard0001']));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Onboard0001', chargesEnabled: true));

    get(appUrl('/gateways/stripe/onboarding/'.$connection->id.'/return'))
        ->assertRedirect(StripeConnection::getUrl(panel: 'app'))
        ->assertSessionHas('gateways.flash', 'gateways.onboarding.returned');

    $connection->refresh();
    $tenantAudit = AuditLog::query()->where('action', AuditAction::TenantStatusChanged->value)->firstOrFail();

    expect($connection->status)->toBe(ConnectionStatus::Active)
        ->and($connection->charges_enabled)->toBeTrue()
        ->and($connection->connected_at)->not->toBeNull()
        ->and(stripeHttp()->requestsTo('get', '/v1/account')[0]['headers']['stripe-account'])->toBe('acct_Onboard0001')
        ->and(tenantOf($owner)->status)->toBe(TenantStatus::Active)
        ->and($tenantAudit->actor_type)->toBe(ActorType::System)
        ->and(AuditLog::query()->where('action', AuditAction::GatewayStatusChanged->value)->count())->toBe(1);

    Notification::assertSentTo($owner, GatewayConnectionNotification::class, static fn (GatewayConnectionNotification $n): bool => $n->notice === ConnectionNotice::Connected);
});

it('keeps an incomplete onboarding in onboarding and lists the requirements', function (): void {
    $owner = onboardingOwner();
    $connection = GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->onboarding()->state(['provider_account_id' => 'acct_Onboard0001']));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Onboard0001', chargesEnabled: false, currentlyDue: ['external_account', 'company.tax_id']));

    get(appUrl('/gateways/stripe/onboarding/'.$connection->id.'/return'))->assertRedirect();

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Onboarding)
        ->and($connection->requirements['currently_due'] ?? null)->toBe(['external_account', 'company.tax_id'])
        ->and(tenantOf($owner)->status)->toBe(TenantStatus::PendingOnboarding);

    Livewire::test(StripeConnection::class)
        ->assertSee('external_account')
        ->assertSee(__('gateways.callout.onboarding.heading'))
        ->assertActionVisible('continueOnboarding');
});

it('moves an active connection to restricted when Stripe disables charges', function (): void {
    $owner = onboardingOwner(TenantStatus::Active);
    $connection = GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->state(['provider_account_id' => 'acct_Onboard0001']));
    stripeHttp()->on('get', '/v1/account', StripeFixtures::account('acct_Onboard0001', chargesEnabled: false));

    get(appUrl('/gateways/stripe/onboarding/'.$connection->id.'/return'))->assertRedirect();

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Restricted);
    Notification::assertSentTo($owner, GatewayConnectionNotification::class, static fn (GatewayConnectionNotification $n): bool => $n->notice === ConnectionNotice::Restricted);
});

it('issues a new link from the refresh URL only inside the re-authentication window', function (): void {
    $owner = onboardingOwner();
    $connection = GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->onboarding()->state(['provider_account_id' => 'acct_Onboard0001']));
    $refresh = appUrl('/gateways/stripe/onboarding/'.$connection->id.'/refresh');

    get($refresh)
        ->assertRedirect(StripeConnection::getUrl(panel: 'app'))
        ->assertSessionHas('gateways.flash', 'gateways.onboarding.confirm_to_continue');
    expect(stripeHttp()->requestsTo('post', '/v1/account_links'))->toBe([]);

    GatewayTestHelpers::reauthenticated();
    get($refresh)->assertRedirect('https://connect.stripe.com/setup/s/fake-link');
});

it('starts the onboarding from the panel page', function (): void {
    onboardingOwner();
    GatewayTestHelpers::reauthenticated();

    Livewire::test(StripeConnection::class)
        ->assertSee(__('gateways.connect.onboarding.heading'))
        ->assertSee(__('gateways.connect.api_key.heading'))
        ->callAction('startOnboarding')
        ->assertHasNoActionErrors()
        ->assertRedirect('https://connect.stripe.com/setup/s/fake-link');
});

it('refuses another method while one is connected', function (): void {
    $owner = onboardingOwner();
    GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->apiKey());
    GatewayTestHelpers::reauthenticated();

    app(StartPlatformOnboarding::class)->handle($owner, 'MX');
})->throws(GatewayConnectionException::class);
