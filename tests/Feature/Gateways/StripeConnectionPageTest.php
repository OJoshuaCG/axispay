<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\MessageBag;
use Livewire\Livewire;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeApiKeyScenario;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/**
 * The "Stripe connection" page of the tenant panel (plan 12.3, 17.3):
 * `gateway:manage` only, both methods offered, the restricted key is never
 * shown back, and a refused key does not travel back in the response.
 */
beforeEach(function (): void {
    startSession();
    Notification::fake();
});

it('offers both connection methods in English and Spanish', function (string $locale): void {
    app()->setLocale($locale);
    actingAsTenantUser(tenantUser());

    Livewire::test(StripeConnection::class)
        ->assertSee(__('gateways.page.title'))
        ->assertSee(__('gateways.connect.onboarding.heading'))
        ->assertSee(__('gateways.connect.api_key.heading'))
        ->assertSee(__('gateways.page.subheading.test'))
        ->assertActionVisible('startOnboarding')
        ->assertActionVisible('connectApiKey')
        ->assertActionHidden('disconnect');
})->with(['en', 'es']);

it('hides the api_key option when the platform disables it', function (): void {
    config(['axispay.gateways.stripe.connection_methods.api_key' => false]);
    actingAsTenantUser(tenantUser());

    Livewire::test(StripeConnection::class)
        ->assertDontSee(__('gateways.connect.api_key.heading'))
        ->assertSee(__('gateways.connect.onboarding.heading'));
});

it('is only reachable with gateway:manage', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    get(appUrl('/settings/stripe'))->assertForbidden();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Owner]));
    get(appUrl('/settings/stripe'))->assertOk()->assertSee(__('gateways.page.title'));
});

it('connects with API keys from the page and only shows the masked key afterwards', function (): void {
    actingAsTenantUser(tenantUser(Tenant::factory()->create()));
    GatewayTestHelpers::reauthenticated();
    (new StripeApiKeyScenario(stripeHttp()))->install();
    $secret = GatewayTestHelpers::restrictedKey();

    $component = submitAction(Livewire::test(StripeConnection::class), 'connectApiKey', [
        'restricted_key' => $secret,
        'publishable_key' => GatewayTestHelpers::publishableKey(),
        'risk_acknowledged' => true,
    ])
        ->assertHasNoActionErrors()
        ->assertSee('rk_test_…A1b2')
        ->assertSee('acct_Merchant0001')
        ->assertActionVisible('updateKeys')
        ->assertActionVisible('disconnect');

    expect($component->html())->not->toContain($secret)
        ->and(json_encode($component->get('mountedActions')))->not->toContain($secret);
});

it('shows why a key was refused and clears it from the form (case 19)', function (): void {
    actingAsTenantUser(tenantUser());
    GatewayTestHelpers::reauthenticated();
    (new StripeApiKeyScenario(stripeHttp()))->without('charge_read')->install();
    $secret = GatewayTestHelpers::restrictedKey();

    $component = submitAction(Livewire::test(StripeConnection::class), 'connectApiKey', [
        'restricted_key' => $secret,
        'publishable_key' => GatewayTestHelpers::publishableKey(),
        'risk_acknowledged' => true,
    ])
        ->assertHasActionErrors(['restricted_key']);

    $errors = $component->errors();

    expect($errors instanceof MessageBag ? $errors->first('mountedActions.0.data.restricted_key') : null)
        ->toBe(__('gateways.api_key.errors.missing_permissions', ['details' => 'charge_read']))
        ->and($component->html())->not->toContain($secret)
        ->and(json_encode($component->get('mountedActions')))->not->toContain($secret);
});

it('refuses a secret key from the page with the explanation', function (): void {
    actingAsTenantUser(tenantUser());
    GatewayTestHelpers::reauthenticated();

    submitAction(Livewire::test(StripeConnection::class), 'connectApiKey', [
        'restricted_key' => GatewayTestHelpers::fakeKey('sk', false, '51FakeSecretKey000000000000'),
        'publishable_key' => GatewayTestHelpers::publishableKey(),
        'risk_acknowledged' => true,
    ])
        ->assertHasActionErrors(['restricted_key']);

    expect(stripeHttp()->requests)->toBe([]);
});

it('asks for the extra confirmation of excessive permissions in live mode', function (): void {
    actingAsTenantUser(tenantUser(), livemode: true);
    GatewayTestHelpers::reauthenticated();
    (new StripeApiKeyScenario(stripeHttp()))->with('payout_write')->install();
    $data = [
        'restricted_key' => GatewayTestHelpers::restrictedKey(true),
        'publishable_key' => GatewayTestHelpers::publishableKey(true),
        'risk_acknowledged' => true,
    ];

    $component = submitAction(Livewire::test(StripeConnection::class), 'connectApiKey', $data)
        ->assertHasActionErrors(['accept_excessive_permissions']);

    // The refused key was erased from the form: it is pasted again with the confirmation.
    $component->assertSet('excessivePermissions', ['payout_write'])
        ->assertActionMounted('connectApiKey')
        ->assertSet('mountedActions.0.data.restricted_key', null);

    submitAction($component, 'connectApiKey', [...$data, 'accept_excessive_permissions' => true], mount: false)
        ->assertHasNoActionErrors()
        ->assertSee(__('gateways.details.excessive_heading'));
});

it('asks for the password when the re-authentication window is closed', function (): void {
    actingAsTenantUser(tenantUser());

    Livewire::test(StripeConnection::class)
        ->mountAction('startOnboarding')
        ->assertActionMounted('startOnboarding')
        ->callMountedAction()
        ->assertHasActionErrors(['current_password' => 'required']);

    expect(stripeHttp()->requests)->toBe([]);
});

it('disconnects from the page', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    GatewayTestHelpers::connection(tenantOf($owner));
    GatewayTestHelpers::reauthenticated();

    Livewire::test(StripeConnection::class)
        ->assertActionVisible('disconnect')
        ->callAction('disconnect')
        ->assertHasNoActionErrors()
        ->assertSee(__('gateways.connect.onboarding.heading'));
});

it('tells a test-mode api_key connection that the account is not activated, without claiming payments are blocked (ADR-0055)', function (string $locale): void {
    app()->setLocale($locale);
    $owner = actingAsTenantUser(tenantUser());
    GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->apiKey()->state([
        'charges_enabled' => false,
        'payouts_enabled' => false,
        'requirements' => ['currently_due' => [], 'eventually_due' => [], 'past_due' => [], 'pending_verification' => [], 'disabled_reason' => 'requirements.past_due', 'current_deadline' => null],
    ]));

    Livewire::test(StripeConnection::class)
        ->assertSee(__('gateways.callout.not_activated.heading'))
        ->assertSee(__('gateways.callout.not_activated.body'))
        ->assertSee(__('gateways.requirements.reason.live_activation'))
        ->assertSee(__('gateways.status.active'))
        ->assertDontSee(__('gateways.callout.restricted.heading'))
        ->assertDontSee(__('gateways.requirements.reason.information_needed'));
})->with(['en', 'es']);

it('keeps the paused-payments warning for a live api_key connection without charges (ADR-0055)', function (): void {
    $owner = actingAsTenantUser(tenantUser(), livemode: true);
    GatewayTestHelpers::connection(tenantOf($owner), livemode: true, state: static fn ($factory) => $factory->apiKey(GatewayTestHelpers::restrictedKey(true), GatewayTestHelpers::publishableKey(true))->state([
        'status' => ConnectionStatus::Restricted,
        'charges_enabled' => false,
    ]));

    Livewire::test(StripeConnection::class)
        ->assertSee(__('gateways.callout.restricted.heading'))
        ->assertDontSee(__('gateways.callout.not_activated.heading'));
});

it('lists the requirements Stripe is still verifying apart from the due ones', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->onboarding()->state([
        'requirements' => ['currently_due' => ['external_account'], 'eventually_due' => [], 'past_due' => [], 'pending_verification' => ['individual.verification.document'], 'disabled_reason' => null, 'current_deadline' => null],
    ]));

    Livewire::test(StripeConnection::class)
        ->assertSee(__('gateways.requirements.intro', ['count' => 1]))
        ->assertSee('external_account')
        ->assertSee(__('gateways.requirements.pending_intro', ['count' => 1]))
        ->assertSee('individual.verification.document');
});

it('shows the requirements section when Stripe is only verifying items', function (): void {
    $owner = actingAsTenantUser(tenantUser());
    GatewayTestHelpers::connection(tenantOf($owner), state: static fn ($factory) => $factory->state([
        'requirements' => ['currently_due' => [], 'eventually_due' => [], 'past_due' => [], 'pending_verification' => ['company.verification.document'], 'disabled_reason' => null, 'current_deadline' => null],
    ]));

    Livewire::test(StripeConnection::class)
        ->assertSee(__('gateways.requirements.heading'))
        ->assertSee('company.verification.document')
        ->assertDontSee(__('gateways.requirements.intro', ['count' => 1]));
});
