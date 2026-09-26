<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Filament\Support\PaymentLinkForm;
use App\Modules\Tenancy\Data\TenantSettings;

/**
 * The panel's create form → API input (ADR-0049 UI review).
 */
it('strips the thousands separators the panel shows, and nothing else', function (): void {
    expect(PaymentLinkForm::toInput(['amount' => ' 12,500.00 '])['amount'])->toBe('12500.00')
        ->and(PaymentLinkForm::toInput(['amount' => '1.001'])['amount'])->toBe('1.001');
});

it('maps a preset or the custom hours to expires_in_hours', function (): void {
    expect(PaymentLinkForm::toInput(['expiry' => '72'])['expires_in_hours'])->toBe(72)
        ->and(PaymentLinkForm::toInput(['expiry' => 168, 'expires_in_hours' => '5'])['expires_in_hours'])->toBe(168)
        ->and(PaymentLinkForm::toInput(['expiry' => 'custom', 'expires_in_hours' => '5'])['expires_in_hours'])->toBe(5)
        ->and(PaymentLinkForm::toInput(['expiry' => 'custom']))->not->toHaveKey('expires_in_hours');
});

it('offers only the presets the tenant allows, the tenant default preselected', function (): void {
    $settings = TenantSettings::fromArray(['links' => ['max_expiration_hours' => 200, 'default_expiration_hours' => 72]]);

    expect(array_keys(PaymentLinkForm::expiryOptions($settings)))->toBe([24, 72, 168, 'custom'])
        ->and(PaymentLinkForm::defaultExpiry($settings))->toBe(72)
        ->and(PaymentLinkForm::defaultExpiry(TenantSettings::fromArray(['links' => ['default_expiration_hours' => 10]])))->toBe('custom');
});

it('sends metadata as an object and drops rows without a key', function (): void {
    $input = PaymentLinkForm::toInput(['metadata' => ['0' => 'a', 'order' => 'A-1', ' ' => 'x']]);

    expect($input['metadata'])->toBeInstanceOf(stdClass::class)
        ->and((array) $input['metadata'])->toBe(['0' => 'a', 'order' => 'A-1'])
        ->and(json_encode($input['metadata']))->toBe('{"0":"a","order":"A-1"}')
        ->and(PaymentLinkForm::toInput(['metadata' => []]))->not->toHaveKey('metadata');
});
