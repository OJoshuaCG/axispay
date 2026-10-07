<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Fx\Enums\FxMode;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Tenancy\Actions\UpdateTenantPaymentSettings;
use App\Modules\Tenancy\Data\TenantPaymentSettingsData;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Exceptions\InvalidPaymentSettingsException;
use App\Modules\Tenancy\Filament\Pages\TenantPaymentSettings;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * Settings -> Payment settings in the tenant panel (ADR-0063, ADR-0048): the
 * currency conversion (`fx.*`) and the link expiration default and maximum,
 * managed with `settings:manage`, checked against the platform limits,
 * audited, read-only for suspended or closed tenants and during
 * impersonation, and never touching another tenant.
 */

beforeEach(function (): void {
    startSession();
});

/**
 * @param  array<mixed>  $overrides
 * @return array<string, mixed> a valid form state
 */
function paymentSettingsForm(array $overrides = []): array
{
    $state = [
        'fx_conversion_enabled' => true,
        'fx_default_mode' => 'fixed',
        'fx_fixed_rate' => '20',
        'fx_markup_bps' => 0,
        'fx_quote_validity_minutes' => 30,
        'links_default_expiration_hours' => 168,
        'links_max_expiration_hours' => 1440,
    ];

    foreach ($overrides as $field => $value) {
        $state[(string) $field] = $value;
    }

    return $state;
}

function savedSettings(Tenant $tenant): TenantSettings
{
    return Tenant::query()->findOrFail($tenant->id)->settings();
}

it('is reachable with settings:manage only (owner and admin)', function (): void {
    $tenant = activeTenant();

    foreach ([SystemRole::Viewer, SystemRole::Finance, SystemRole::LinkCreator, SystemRole::IntegrationManager] as $role) {
        actingAsTenantUser(tenantUser($tenant, [$role]));
        get(appUrl('/settings/payments'))->assertForbidden();
        expect(TenantPaymentSettings::canAccess())->toBeFalse();
    }

    foreach ([SystemRole::Owner, SystemRole::Admin] as $role) {
        expect($role->permissions())->toContain(TenantPermission::SettingsManage);
        actingAsTenantUser(tenantUser($tenant, [$role]));
        get(appUrl('/settings/payments'))->assertOk()->assertSee(__('fx.settings.title'), false);
    }
});

it('opens with the stored values, in English and Spanish', function (string $locale): void {
    app()->setLocale($locale);
    $tenant = activeTenant();
    $tenant->forceFill(['settings' => [
        'links' => ['default_expiration_hours' => 72, 'max_expiration_hours' => 720],
        'fx' => ['conversion_enabled' => true, 'default_mode' => 'banxico_fix', 'markup_bps' => 150, 'quote_validity_minutes' => 45, 'fixed_rate' => '20.5'],
    ]])->save();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantPaymentSettings::class)
        ->assertOk()
        ->assertSee(__('fx.settings.fx.heading'))
        ->assertSee(__('fx.settings.links.heading'))
        ->assertFormSet([
            'fx_conversion_enabled' => true,
            'fx_default_mode' => 'banxico_fix',
            'fx_fixed_rate' => '20.500000',
            'fx_markup_bps' => 150,
            'fx_quote_validity_minutes' => 45,
            'links_default_expiration_hours' => 72,
            'links_max_expiration_hours' => 720,
        ]);
})->with(['en', 'es']);

it('saves the fixed rate and the expiration, keeping the rest of the settings document', function (): void {
    $tenant = activeTenant();
    $tenant->forceFill(['settings' => [
        'links' => ['max_amount_minor' => ['USD' => 50_000]],
        'checkout' => ['locale' => 'en', 'send_stripe_receipts' => true],
        'payer_fields' => ['email' => 'required'],
    ]])->save();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantPaymentSettings::class)
        ->fillForm(paymentSettingsForm(['fx_fixed_rate' => '20', 'links_default_expiration_hours' => 24, 'links_max_expiration_hours' => 720]))
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('fx.settings.saved'));

    $settings = savedSettings($tenant);

    expect($settings->fxConversionEnabled)->toBeTrue()
        ->and($settings->fxDefaultMode)->toBe(FxMode::Fixed)
        ->and($settings->fxFixedRate?->toString())->toBe('20.000000')
        ->and($settings->defaultExpirationHours)->toBe(24)
        ->and($settings->maxExpirationHours)->toBe(720)
        ->and($settings->maxAmountMinor)->toBe(['USD' => 50_000])
        ->and($settings->sendStripeReceipts)->toBeTrue()
        ->and($settings->payerFields)->toHaveCount(1);
});

it('saves the Banxico mode with its markup and quote validity', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantPaymentSettings::class)
        ->fillForm(paymentSettingsForm(['fx_default_mode' => 'banxico_fix', 'fx_fixed_rate' => null, 'fx_markup_bps' => 250, 'fx_quote_validity_minutes' => 60]))
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = savedSettings($tenant);

    expect($settings->fxDefaultMode)->toBe(FxMode::BanxicoFix)
        ->and($settings->fxMarkupBps)->toBe(250)
        ->and($settings->fxQuoteValidityMinutes)->toBe(60)
        ->and($settings->fxFixedRate)->toBeNull();
});

it('does not need a rate while the conversion is off, and the off switch keeps the stored rate', function (): void {
    $tenant = activeTenant();
    $tenant->forceFill(['settings' => ['fx' => ['conversion_enabled' => true, 'default_mode' => 'fixed', 'fixed_rate' => '20']]])->save();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantPaymentSettings::class)
        ->fillForm(paymentSettingsForm(['fx_conversion_enabled' => false]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(savedSettings($tenant)->fxConversionEnabled)->toBeFalse()
        ->and(savedSettings($tenant)->fxFixedRate?->toString())->toBe('20.000000');
});

it('rejects values outside the platform limits, naming the field', function (array $overrides, string $field): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantPaymentSettings::class)
        ->fillForm(paymentSettingsForm($overrides))
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(savedSettings($tenant)->fxFixedRate)->toBeNull()
        ->and(savedSettings($tenant)->maxExpirationHours)->toBe(1440);
})->with([
    'fixed rate missing in fixed mode' => [['fx_fixed_rate' => ''], 'fx_fixed_rate'],
    'fixed rate zero' => [['fx_fixed_rate' => '0'], 'fx_fixed_rate'],
    'fixed rate with 7 decimals' => [['fx_fixed_rate' => '20.1234567'], 'fx_fixed_rate'],
    'fixed rate not a number' => [['fx_fixed_rate' => 'abc'], 'fx_fixed_rate'],
    'markup above 1000 bps' => [['fx_default_mode' => 'banxico_fix', 'fx_markup_bps' => 1001], 'fx_markup_bps'],
    'markup negative' => [['fx_default_mode' => 'banxico_fix', 'fx_markup_bps' => -1], 'fx_markup_bps'],
    'quote validity below 5' => [['fx_default_mode' => 'banxico_fix', 'fx_quote_validity_minutes' => 4], 'fx_quote_validity_minutes'],
    'quote validity above 120' => [['fx_default_mode' => 'banxico_fix', 'fx_quote_validity_minutes' => 121], 'fx_quote_validity_minutes'],
    'maximum above 60 days' => [['links_max_expiration_hours' => 1441], 'links_max_expiration_hours'],
    'maximum below one hour' => [['links_max_expiration_hours' => 0, 'links_default_expiration_hours' => 0], 'links_max_expiration_hours'],
    'default above the maximum' => [['links_default_expiration_hours' => 200, 'links_max_expiration_hours' => 100], 'links_default_expiration_hours'],
    'default below one hour' => [['links_default_expiration_hours' => 0], 'links_default_expiration_hours'],
    'unknown mode' => [['fx_default_mode' => 'magic'], 'fx_default_mode'],
]);

it('also refuses invalid values in the action itself, whatever the page sent', function (): void {
    $tenant = activeTenant();
    $user = tenantUser($tenant);
    actingAsTenantUser($user);

    $attempt = static fn (TenantPaymentSettingsData $data): array => (static function () use ($user, $data): array {
        try {
            app(UpdateTenantPaymentSettings::class)->handle($user, $data);
        } catch (InvalidPaymentSettingsException $e) {
            return array_keys($e->errors);
        }

        return [];
    })();

    $valid = new TenantPaymentSettingsData(true, FxMode::Fixed, ExchangeRate::of('20'), 0, 30, 168, 1440);

    expect($attempt($valid))->toBe([])
        ->and($attempt(new TenantPaymentSettingsData(true, FxMode::Fixed, null, 0, 30, 168, 1440)))->toBe(['fx_fixed_rate'])
        ->and($attempt(new TenantPaymentSettingsData(true, FxMode::None, null, 0, 30, 168, 1440)))->toBe(['fx_default_mode'])
        ->and($attempt(new TenantPaymentSettingsData(true, FxMode::BanxicoFix, null, 1001, 4, 0, 99999)))->toBe(['fx_markup_bps', 'fx_quote_validity_minutes', 'links_max_expiration_hours', 'links_default_expiration_hours']);
});

it('audits the change with the old and the new values', function (): void {
    $tenant = activeTenant();
    $user = tenantUser($tenant);
    actingAsTenantUser($user);

    Livewire::test(TenantPaymentSettings::class)
        ->fillForm(paymentSettingsForm(['fx_fixed_rate' => '19.5']))
        ->call('save')
        ->assertHasNoFormErrors();

    $entries = AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('action', AuditAction::TenantPaymentSettingsUpdated->value)->get();

    expect($entries)->toHaveCount(1);

    $entry = $entries->sole();

    expect($entry->actor_id)->toBe($user->id)
        ->and(data_get($entry->changes, 'before.fx_fixed_rate'))->toBeNull()
        ->and(data_get($entry->changes, 'after.fx_fixed_rate'))->toBe('19.500000')
        ->and(data_get($entry->changes, 'after.fx_conversion_enabled'))->toBeTrue();
});

it('changes only the signed-in tenant', function (): void {
    $own = activeTenant();
    $other = activeTenant();
    $other->forceFill(['settings' => ['fx' => ['fixed_rate' => '17']]])->save();
    actingAsTenantUser(tenantUser($own));

    Livewire::test(TenantPaymentSettings::class)->fillForm(paymentSettingsForm())->call('save')->assertHasNoFormErrors();

    expect(savedSettings($own)->fxFixedRate?->toString())->toBe('20.000000')
        ->and(savedSettings($other)->fxFixedRate?->toString())->toBe('17.000000');
});

it('is read-only for a suspended or closed tenant: it opens but does not save', function (TenantStatus $status): void {
    $tenant = Tenant::factory()->status($status)->create();
    actingAsTenantUser(tenantUser($tenant));

    expect(TenantPaymentSettings::canAccess())->toBeTrue();

    Livewire::test(TenantPaymentSettings::class)
        ->assertOk()
        ->call('save')
        ->assertForbidden();

    expect(savedSettings($tenant)->fxFixedRate)->toBeNull();
})->with([TenantStatus::Suspended, TenantStatus::Closed]);

it('refuses the action to a user without settings:manage', function (): void {
    $tenant = activeTenant();
    $viewer = tenantUser($tenant, [SystemRole::Viewer]);
    $data = new TenantPaymentSettingsData(true, FxMode::Fixed, ExchangeRate::of('20'), 0, 30, 168, 1440);

    expect(fn () => app(UpdateTenantPaymentSettings::class)->handle($viewer, $data))->toThrow(AuthorizationException::class)
        ->and(savedSettings($tenant)->fxFixedRate)->toBeNull();
});

it('applies the saved defaults to the links created afterwards', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantPaymentSettings::class)
        ->fillForm(paymentSettingsForm(['links_default_expiration_hours' => 48, 'links_max_expiration_hours' => 96]))
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = savedSettings($tenant);

    expect($settings->defaultExpirationHours)->toBe(48)->and($settings->maxExpirationHours)->toBe(96);
});
