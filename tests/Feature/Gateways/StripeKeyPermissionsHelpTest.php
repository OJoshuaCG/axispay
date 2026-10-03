<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Gateways\Stripe\Connection\ApiKeyFlow;
use App\Modules\Gateways\Stripe\Connection\StripeKeyPermissions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Uid\Ulid;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeApiKeyScenario;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * "View required permissions" on the Stripe connection page (api_key
 * method, ADR-0047): the restricted key's permissions and how to create it.
 * The help reads StripeKeyPermissions, the same catalog ApiKeyFlow validates
 * against, so what it lists is exactly what the validation checks.
 */

beforeEach(function (): void {
    startSession();
    Notification::fake();
});

/**
 * The permission identifiers a rendered help lists inside one block
 * (`data-permission` attributes), without duplicates.
 *
 * @return list<string>
 */
function helpPermissions(string $html): array
{
    preg_match_all('/data-permission="([a-z_]+)"/', $html, $matches);

    return array_values(array_unique($matches[1]));
}

function renderedPermissionsHelp(string $locale = 'en'): string
{
    app()->setLocale($locale);

    return view('filament.gateways.stripe-key-permissions', ['mode' => __('gateways.mode.test')])->render();
}

/** The part of the help between the dangerous block's opening tag and its end. */
function dangerousBlock(string $html): string
{
    $start = strpos($html, 'data-dangerous-permissions');
    expect($start)->not->toBeFalse();

    return substr($html, (int) $start, (int) strpos($html, '</div>', (int) $start) - (int) $start);
}

// --- Single source of truth ------------------------------------------------

it('keeps one catalog: the required and dangerous sets of ADR-0047', function (): void {
    expect(StripeKeyPermissions::required())->toEqualCanonicalizing([
        'connected_account_read', 'token_read', 'webhook_write',
        'payment_intent_write', 'charge_write', 'charge_read', 'dispute_read',
        'event_read', 'payment_method_read', 'confirmation_token_read',
    ])
        ->and(StripeKeyPermissions::dangerous())->toEqualCanonicalizing(['payout_write', 'transfer_write', 'balance_read'])
        ->and(array_intersect(StripeKeyPermissions::required(), StripeKeyPermissions::dangerous()))->toBe([]);

    // ApiKeyFlow keeps no permission list of its own.
    $constants = array_keys((new ReflectionClass(ApiKeyFlow::class))->getConstants());
    expect(array_values(array_filter($constants, static fn (string $name): bool => str_contains($name, 'PERMISSION') || (str_contains($name, 'PROBE') && $name !== 'PROBE_ID_NUMBER'))))->toBe([]);
});

it('validates exactly the catalog: every probed permission is reported missing, every dangerous one excessive', function (): void {
    actingAsTenantUser(tenantUser());
    $scenario = (new StripeApiKeyScenario(stripeHttp()))->install();
    $credentials = ApiKeyCredentials::from(GatewayTestHelpers::restrictedKey(), GatewayTestHelpers::publishableKey());

    // Missing: everything ApiKeyFlow checks before storing the key. The
    // account read (step 3) and the webhook endpoint (created after
    // validation) prove the other two required permissions.
    $scenario->without(...array_diff(StripeKeyPermissions::required(), ['connected_account_read', 'webhook_write']));
    $e = thrownBy(ApiKeyValidationException::class, static fn () => app(ApiKeyFlow::class)->validate($credentials, false, (string) new Ulid));

    expect($e->rejection)->toBe(ApiKeyRejection::MissingPermissions)
        ->and($e->details)
        ->toEqualCanonicalizing(array_values(array_diff(StripeKeyPermissions::required(), ['connected_account_read', 'webhook_write'])));

    // Excessive: exactly the dangerous set.
    $scenario->with(...StripeKeyPermissions::required())->with(...StripeKeyPermissions::dangerous());
    $result = app(ApiKeyFlow::class)->validate($credentials, false, (string) new Ulid);

    expect($result->excessive)->toEqualCanonicalizing(StripeKeyPermissions::dangerous())
        ->and($result->granted)->toEqualCanonicalizing(array_values(array_diff(StripeKeyPermissions::required(), ['webhook_write'])));
});

it('lists exactly the required set in the table and the dangerous set in the warning', function (): void {
    $html = renderedPermissionsHelp();
    $dangerous = dangerousBlock($html);
    $required = str_replace($dangerous, '', $html);

    expect(helpPermissions($required))->toEqualCanonicalizing(StripeKeyPermissions::required())
        ->and(helpPermissions($dangerous))->toEqualCanonicalizing(StripeKeyPermissions::dangerous());

    foreach (StripeKeyPermissions::required() as $permission) {
        expect(str_contains($html, '>'.$permission.'<') || str_contains($html, $permission."\n"))->toBeTrue();
    }
});

// --- Copy ------------------------------------------------------------------

it('has every string of the help in English and Spanish', function (string $locale): void {
    app()->setLocale($locale);
    $keys = [
        'action', 'heading', 'form_heading', 'close', 'intro', 'steps_heading', 'table_heading',
        'columns.resource', 'columns.level', 'columns.why', 'columns.identifier',
        'level.read', 'level.write', 'dangerous.heading', 'dangerous.body', 'labels_note', 'docs_link', 'new_tab',
        ...array_map(static fn (int $step): string => "steps.{$step}", range(1, 7)),
        ...array_map(static fn (string $p): string => "resources.{$p}", [...StripeKeyPermissions::required(), ...StripeKeyPermissions::dangerous()]),
        ...array_map(static fn (string $p): string => "reasons.{$p}", StripeKeyPermissions::required()),
    ];

    foreach ($keys as $key) {
        $full = 'gateways.permissions_help.'.$key;
        expect(trans()->hasForLocale($full, $locale))->toBeTrue("Missing {$locale} string: {$full}");
    }
})->with(['en', 'es']);

it('renders the steps, the mode, the Stripe link and its new-tab label in each language', function (string $locale, string $close, string $newTab): void {
    $html = renderedPermissionsHelp($locale);

    expect(str_contains($html, e(__('gateways.permissions_help.steps.1', ['mode' => __('gateways.mode.test')]))))->toBeTrue()
        ->and(str_contains($html, 'href="https://docs.stripe.com/keys#create-restricted-api-secret-key" target="_blank" rel="noopener noreferrer"'))->toBeTrue()
        ->and(str_contains($html, '<span class="sr-only">'.e($newTab).'</span>'))->toBeTrue()
        ->and(__('gateways.permissions_help.close'))->toBe($close);
})->with([
    'en' => ['en', 'Close', '(opens in a new tab)'],
    'es' => ['es', 'Cerrar', '(se abre en una pestaña nueva)'],
]);

// --- Page ------------------------------------------------------------------

it('offers the help next to the api_key method when it is enabled', function (): void {
    actingAsTenantUser(tenantUser());

    Livewire::test(StripeConnection::class)
        ->assertActionVisible('apiKeyPermissions')
        ->assertSee(__('gateways.permissions_help.action'));
});

it('hides the help when the api_key method is disabled', function (): void {
    config(['axispay.gateways.stripe.connection_methods.api_key' => false]);
    actingAsTenantUser(tenantUser());

    Livewire::test(StripeConnection::class)
        ->assertActionHidden('apiKeyPermissions')
        ->assertDontSee(__('gateways.permissions_help.action'));
});

it('opens a read-only modal with every required identifier and the three dangerous ones in the warning', function (): void {
    actingAsTenantUser(tenantUser());

    $component = Livewire::test(StripeConnection::class)
        ->mountAction('apiKeyPermissions')
        ->assertActionMounted('apiKeyPermissions');

    $component->assertMountedActionModalSee(__('gateways.permissions_help.heading'));
    $component->assertMountedActionModalSee(__('gateways.permissions_help.dangerous.heading'));
    $component->assertMountedActionModalSee(__('gateways.permissions_help.close'));

    // The modal's own markup: the page's `html()` does not include modals.
    $html = $component->getMountedActionModalHtml();
    $dangerous = dangerousBlock($html);

    foreach (StripeKeyPermissions::required() as $permission) {
        expect(str_contains($html, 'data-permission="'.$permission.'"'))->toBeTrue();
    }

    expect(helpPermissions($dangerous))->toEqualCanonicalizing(['payout_write', 'transfer_write', 'balance_read']);

    // Closing it changes nothing.
    $component->callMountedAction()->assertHasNoActionErrors();
    expect(stripeHttp()->requests)->toBe([]);
});

it('puts the same help, collapsed, inside the connect and update keys forms', function (): void {
    $owner = actingAsTenantUser(tenantUser());

    $connect = Livewire::test(StripeConnection::class)->mountAction('connectApiKey');
    $connectHtml = $connect->getMountedActionModalHtml();
    expect(str_contains($connectHtml, 'data-stripe-key-permissions'))->toBeTrue()
        ->and(str_contains($connectHtml, e(__('gateways.permissions_help.form_heading'))))->toBeTrue();

    GatewayTestHelpers::connection(tenantOf($owner), false, static fn ($factory) => $factory->apiKey());

    $update = Livewire::test(StripeConnection::class)->mountAction('updateKeys');
    expect(str_contains($update->getMountedActionModalHtml(), 'data-stripe-key-permissions'))->toBeTrue();
});

it('keeps the connect section, and its help, from users without gateway:manage', function (): void {
    actingAsTenantUser(tenantUser(activeTenant(), [SystemRole::Viewer]));

    get(appUrl('/settings/stripe'))->assertForbidden();
});
