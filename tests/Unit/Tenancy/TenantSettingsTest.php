<?php

declare(strict_types=1);

use App\Modules\Fx\Enums\FxMode;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Enums\CheckoutLocale;

it('provides defaults for an empty settings document', function (): void {
    $settings = TenantSettings::defaults();

    expect($settings->defaultExpirationHours)->toBe(168)
        ->and($settings->maxExpirationHours)->toBe(2160)
        ->and($settings->fxConversionEnabled)->toBeFalse()
        ->and($settings->checkoutLocale)->toBe(CheckoutLocale::Es);
});

it('clamps values to the platform limits and drops invalid ones', function (): void {
    $settings = TenantSettings::fromArray([
        'links' => ['default_expiration_hours' => 99999, 'max_expiration_hours' => 99999],
        'fx' => ['markup_bps' => 5000, 'default_mode' => 'magic', 'conversion_enabled' => 'yes'],
        'checkout' => ['locale' => 'fr'],
    ]);

    expect($settings->maxExpirationHours)->toBe(2160)
        ->and($settings->defaultExpirationHours)->toBe(2160)
        ->and($settings->fxMarkupBps)->toBe(1000)
        ->and($settings->fxDefaultMode)->toBe(FxMode::BanxicoFix)
        ->and($settings->fxConversionEnabled)->toBeFalse()
        ->and($settings->checkoutLocale)->toBe(CheckoutLocale::Es)
        ->and(TenantSettings::fromArray($settings->toArray()))->toEqual($settings);
});

it('clamps the default expiration to the tenant maximum', function (): void {
    $settings = TenantSettings::fromArray(['links' => ['default_expiration_hours' => 500, 'max_expiration_hours' => 48]]);

    expect($settings->maxExpirationHours)->toBe(48)->and($settings->defaultExpirationHours)->toBe(48);
});

it('keeps only valid tenant amount caps, never above the platform cap', function (): void {
    $settings = TenantSettings::fromArray(['links' => ['max_amount_minor' => [
        'usd' => 50_000,
        'MXN' => 999_999_999,
        'EUR' => 1_000,
        'BTC' => 5,
    ]]]);

    expect($settings->maxAmountMinor)->toBe(['USD' => 50_000, 'MXN' => 20_000_000])
        ->and($settings->maxAmountMinorFor(CurrencyCode::USD))->toBe(50_000);

    expect(TenantSettings::fromArray(['links' => ['max_amount_minor' => ['USD' => -5, 'MXN' => '1000']]])->maxAmountMinor)->toBe([])
        ->and(TenantSettings::fromArray(['links' => ['max_amount_minor' => 'all']])->maxAmountMinor)->toBe([]);
});

it('keeps only known payer field requirements', function (): void {
    $settings = TenantSettings::fromArray(['payer_fields' => ['email' => 'required', 'phone' => 'sometimes', 'notes' => 3]]);

    expect($settings->payerFields)->toBe(['email' => PayerFieldRequirement::Required])
        ->and($settings->toArray()['payer_fields'])->toBe(['email' => 'required']);
});
