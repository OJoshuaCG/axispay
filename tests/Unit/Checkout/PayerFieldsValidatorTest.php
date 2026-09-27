<?php

declare(strict_types=1);

use App\Modules\PayerFields\Exceptions\InvalidPayerDataException;
use App\Modules\PayerFields\Services\PayerFieldsValidator;

/**
 * Plan 19.1: the payer field catalog, validated against the configuration
 * frozen on the link.
 */
/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function payerConfig(array $overrides): array
{
    return [...['email' => 'hidden', 'full_name' => 'hidden', 'phone' => 'hidden', 'company_name' => 'hidden', 'billing_address' => 'hidden', 'tax_id' => 'hidden', 'notes' => 'hidden'], ...$overrides];
}

/**
 * @param  array<string, string>  $config
 * @param  array<mixed>  $input
 * @return array<string, string>
 */
function payerErrors(array $config, array $input): array
{
    try {
        app(PayerFieldsValidator::class)->validate(payerConfig($config), $input);
    } catch (InvalidPayerDataException $e) {
        return $e->errors;
    }

    return [];
}

it('normalizes valid values and ignores hidden fields', function (): void {
    $data = app(PayerFieldsValidator::class)->validate(payerConfig(['email' => 'required', 'full_name' => 'optional', 'phone' => 'optional', 'tax_id' => 'optional']), [
        'email' => '  Ana@Example.COM ',
        'full_name' => ' Ana   López ',
        'phone' => '(55) 1234-5678',
        'phone_country' => 'MX',
        'tax_id' => 'lopa800101abc',
        'notes' => 'hidden field, dropped',
    ]);

    expect($data->toArray())->toBe(['email' => 'ana@example.com', 'full_name' => 'Ana López', 'phone' => '+525512345678', 'tax_id' => 'LOPA800101ABC']);
});

it('reports every invalid field without echoing values', function (): void {
    $errors = payerErrors(['email' => 'required', 'full_name' => 'required', 'phone' => 'required', 'notes' => 'optional'], [
        'email' => 'nope',
        'full_name' => 'A',
        'phone' => '12345',
        'phone_country' => 'MX',
        'notes' => str_repeat('x', 501),
    ]);

    expect(array_keys($errors))->toBe(['email', 'full_name', 'phone', 'notes'])
        ->and(implode(' ', $errors))->not->toContain('nope');
});

it('requires required fields and accepts empty optional ones', function (): void {
    expect(payerErrors(['email' => 'required', 'full_name' => 'optional'], []))->toBe(['email' => __('checkout.errors.required')]);
});

it('validates the billing address with the Mexican postal code rule', function (): void {
    $errors = payerErrors(['billing_address' => 'required'], ['billing_address' => ['country' => 'MX', 'line1' => 'Av. Reforma 1', 'city' => 'CDMX', 'state' => 'CDMX', 'postal_code' => '1234']]);
    expect(array_keys($errors))->toBe(['billing_address.postal_code']);

    $data = app(PayerFieldsValidator::class)->validate(payerConfig(['billing_address' => 'required']), ['billing_address' => ['country' => 'US', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701']]);
    expect($data->toArray()['billing_address'])->toBe(['country' => 'US', 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701']);
});

it('accepts foreign phone numbers in E.164 length', function (): void {
    $data = app(PayerFieldsValidator::class)->validate(payerConfig(['phone' => 'required']), ['phone' => '612 345 678', 'phone_country' => 'ES']);

    expect($data->toArray()['phone'])->toBe('+34612345678');
});

it('never serializes payer data', function (): void {
    $data = app(PayerFieldsValidator::class)->validate(payerConfig(['email' => 'required']), ['email' => 'a@b.co']);

    expect(fn () => serialize($data))->toThrow(LogicException::class)
        ->and(print_r($data, true))->not->toContain('a@b.co');
});
