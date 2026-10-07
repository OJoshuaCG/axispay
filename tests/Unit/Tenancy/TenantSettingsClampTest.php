<?php

declare(strict_types=1);

use App\Modules\Tenancy\Data\TenantSettings;

/**
 * The platform lowered the link expiration maximum from 90 to 60 days
 * (1,440 hours, ADR-0048 amended): tenants that stored a larger value are
 * clamped when their settings are read, and links already created keep
 * their own expiry.
 */
it('has a platform maximum of 60 days', function (): void {
    expect(config('axispay.limits.max_expiration_hours'))->toBe(1440);
});

it('clamps a tenant maximum stored under the old 90-day limit', function (): void {
    $settings = TenantSettings::fromArray(['links' => ['max_expiration_hours' => 2160, 'default_expiration_hours' => 2000]]);

    expect($settings->maxExpirationHours)->toBe(1440)
        ->and($settings->defaultExpirationHours)->toBe(1440);
});

it('keeps a tenant maximum at or below the platform limit', function (int $stored, int $expected): void {
    expect(TenantSettings::fromArray(['links' => ['max_expiration_hours' => $stored]])->maxExpirationHours)->toBe($expected);
})->with([
    'exactly 60 days' => [1440, 1440],
    'one hour above' => [1441, 1440],
    'lower' => [720, 720],
    'below the minimum' => [0, 1],
]);

it('keeps the default expiration under the tenant maximum', function (): void {
    $settings = TenantSettings::fromArray(['links' => ['max_expiration_hours' => 336, 'default_expiration_hours' => 720]]);

    expect($settings->maxExpirationHours)->toBe(336)->and($settings->defaultExpirationHours)->toBe(336);
});
