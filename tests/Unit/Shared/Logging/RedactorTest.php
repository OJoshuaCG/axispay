<?php

declare(strict_types=1);

use App\Modules\Shared\Logging\Redactor;

/**
 * Plan section 23.3: secrets and PII never reach the logs.
 */
it('redacts values of sensitive keys, whatever their spelling', function (string $key): void {
    expect((new Redactor)->redactArray([$key => 'value-that-must-not-leak']))
        ->toBe([$key => Redactor::MASK]);
})->with([
    'password', 'password_confirmation', 'current_password', 'secret', 'client_secret',
    'token', 'public_token', 'access_token', 'api_key', 'apiKey', 'X-Api-Key', 'key',
    'Authorization', 'authorization', 'Stripe-Signature', 'webhook-signature', 'cookie', 'Set-Cookie',
    'credentials_secret', 'restricted_key', 'card', 'card_number', 'cvc', 'cvv',
    'email', 'payer_email', 'phone', 'name', 'full_name', 'address', 'billing_address', 'tax_id',
    'two_factor_secret', 'two_factor_recovery_codes',
]);

it('keeps operational fields readable', function (): void {
    $context = [
        'request_id' => 'req_01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
        'tenant_id' => '01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
        'payment_link_id' => 'plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
        'amount_minor' => 15050,
        'currency' => 'USD',
        'livemode' => false,
        'status' => 'succeeded',
        'occurred_at' => '2026-09-23T18:30:00Z',
        'order_number' => '123456789012',
        'publishable_key_prefix' => 'pk_test',
        'duration_ms' => 1234567,
    ];

    expect((new Redactor)->redactArray($context))->toBe($context);
});

it('redacts secret-looking values anywhere in a string', function (string $input, string $expected): void {
    expect((new Redactor)->redactString($input))->toBe($expected);
})->with([
    'stripe secret key' => ['key=sk_live_51HxAbCdEf', 'key=[REDACTED]'],
    'stripe test secret key' => ['sk_test_51HxAbCdEf', '[REDACTED]'],
    'restricted key' => ['using rk_live_51HxAbCdEf now', 'using [REDACTED] now'],
    'webhook secret' => ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', '[REDACTED]'],
    'live api key' => ['plk_live_4eC39HqLyjWDarjtT1zdp7dc', '[REDACTED]'],
    'test api key' => ['Bearer plk_test_4eC39HqLyjWDarjtT1zdp7dc', 'Bearer [REDACTED]'],
    'client secret' => ['pi_3MtwBw_secret_YrKJUKribcBjcG8HVhfZluoGH', '[REDACTED]'],
    'bearer token' => ['Authorization: Bearer abc.def-ghi', 'Authorization: Bearer [REDACTED]'],
    'email' => ['payer jane.doe+tag@example.com.mx paid', 'payer [REDACTED] paid'],
    'PAN' => ['card 4242424242424242 declined', 'card [REDACTED] declined'],
    'PAN with spaces' => ['4242 4242 4242 4242', '[REDACTED]'],
    'PAN with dashes' => ['4000-0566-5566-5556', '[REDACTED]'],
    'amex PAN' => ['378282246310005', '[REDACTED]'],
    'twelve digits survive' => ['order 123456789012', 'order 123456789012'],
    'dates survive' => ['2026-09-23 18:30:00', '2026-09-23 18:30:00'],
]);

it('redacts nested structures and exceptions', function (): void {
    $redacted = (new Redactor)->redactArray([
        'headers' => ['authorization' => ['Bearer plk_live_x'], 'accept' => ['application/json']],
        'payload' => ['data' => ['object' => ['receipt_email' => 'a@b.co', 'note' => 'card 4242424242424242']]],
        'exception' => new RuntimeException('Stripe rejected sk_live_abc123'),
    ]);

    expect($redacted['headers'])->toBe(['authorization' => Redactor::MASK, 'accept' => ['application/json']])
        ->and($redacted['payload'])->toBe(['data' => ['object' => ['receipt_email' => Redactor::MASK, 'note' => 'card [REDACTED]']]])
        ->and($redacted['exception'])->toBeArray()
        ->and(json_encode($redacted['exception']))->not->toContain('sk_live_abc123');
});
