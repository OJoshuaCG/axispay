<?php

declare(strict_types=1);

use App\Modules\Tenancy\Data\TenantSettings;

it('provides defaults for an empty settings document', function (): void {
    $settings = TenantSettings::defaults();

    expect($settings->defaultExpirationHours)->toBe(168)
        ->and($settings->maxExpirationHours)->toBe(2160)
        ->and($settings->fxConversionEnabled)->toBeFalse()
        ->and($settings->checkoutLocale)->toBe('es');
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
        ->and($settings->fxDefaultMode)->toBe('banxico_fix')
        ->and($settings->fxConversionEnabled)->toBeFalse()
        ->and($settings->checkoutLocale)->toBe('es')
        ->and(TenantSettings::fromArray($settings->toArray()))->toEqual($settings);
});
