<?php

declare(strict_types=1);

use App\Modules\Fx\Enums\FxMode;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\PaymentLinks\Services\PaymentLinkInputParser;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Tenancy\Enums\CheckoutLocale;
use Tests\Support\ApiTestHelpers;

/**
 * Field rules of POST /v1/payment_links (plan 8.2, 10.5), without HTTP or
 * database. Rules that depend on the tenant, its gateway or the clock are
 * covered by Feature/PaymentLinks/CreatePaymentLinkApiTest.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function parserInput(array $overrides = []): array
{
    return ApiTestHelpers::body($overrides);
}

/**
 * @param  array<array-key, mixed>  $input
 */
function parserError(array $input): ApiException
{
    return thrownBy(ApiException::class, fn () => app(PaymentLinkInputParser::class)->parse($input));
}

it('rejects each malformed field with its code and param', function (array $input, ApiErrorCode $code, ?string $param): void {
    $e = parserError($input);

    expect($e->errorCode)->toBe($code)->and($e->param)->toBe($param);
})->with([
    'amount as JSON number (case 14)' => [parserInput(['amount' => 1500.5]), ApiErrorCode::AmountMustBeString, 'amount'],
    'amount as JSON integer' => [parserInput(['amount' => 1500]), ApiErrorCode::AmountMustBeString, 'amount'],
    'amount missing' => [['currency' => 'USD', 'description' => 'x'], ApiErrorCode::ParameterMissing, 'amount'],
    'amount with 3 decimals' => [parserInput(['amount' => '10.001']), ApiErrorCode::AmountInvalid, 'amount'],
    'amount with thousands separator' => [parserInput(['amount' => '1,500.00']), ApiErrorCode::AmountInvalid, 'amount'],
    'negative amount' => [parserInput(['amount' => '-5.00']), ApiErrorCode::AmountInvalid, 'amount'],
    'amount in exponent notation' => [parserInput(['amount' => '1e3']), ApiErrorCode::AmountInvalid, 'amount'],
    'amount with spaces' => [parserInput(['amount' => ' 10.00']), ApiErrorCode::AmountInvalid, 'amount'],
    'leading zero' => [parserInput(['amount' => '010.00']), ApiErrorCode::AmountInvalid, 'amount'],
    'USD below the Stripe minimum' => [parserInput(['amount' => '0.49']), ApiErrorCode::AmountBelowMinimum, 'amount'],
    'MXN below the Stripe minimum' => [parserInput(['amount' => '9.99', 'currency' => 'MXN']), ApiErrorCode::AmountBelowMinimum, 'amount'],
    'USD above the platform cap' => [parserInput(['amount' => '10000.01']), ApiErrorCode::AmountAboveMaximum, 'amount'],
    'MXN above the platform cap' => [parserInput(['amount' => '200000.01', 'currency' => 'MXN']), ApiErrorCode::AmountAboveMaximum, 'amount'],
    'unsupported currency' => [parserInput(['currency' => 'EUR']), ApiErrorCode::CurrencyNotSupported, 'currency'],
    'currency missing' => [['amount' => '10.00', 'description' => 'x'], ApiErrorCode::ParameterMissing, 'currency'],
    'description missing' => [['amount' => '10.00', 'currency' => 'USD'], ApiErrorCode::ParameterMissing, 'description'],
    'blank description' => [parserInput(['description' => '   ']), ApiErrorCode::ParameterMissing, 'description'],
    'description too long' => [parserInput(['description' => str_repeat('a', 501)]), ApiErrorCode::ParameterInvalid, 'description'],
    'description not a string' => [parserInput(['description' => 42]), ApiErrorCode::ParameterInvalid, 'description'],
    'unknown parameter' => [parserInput(['expire_in_hours' => 5]), ApiErrorCode::ParameterInvalid, 'expire_in_hours'],
    'metadata as a list' => [parserInput(['metadata' => ['a', 'b']]), ApiErrorCode::MetadataInvalid, 'metadata'],
    'metadata with 21 keys' => [parserInput(['metadata' => array_fill_keys(array_map(static fn (int $i): string => "k{$i}", range(1, 21)), 'v')]), ApiErrorCode::MetadataInvalid, 'metadata'],
    'metadata key with a space' => [parserInput(['metadata' => ['bad key' => 'v']]), ApiErrorCode::MetadataInvalid, 'metadata'],
    'metadata key too long' => [parserInput(['metadata' => [str_repeat('k', 41) => 'v']]), ApiErrorCode::MetadataInvalid, 'metadata'],
    'metadata number value' => [parserInput(['metadata' => ['n' => 5]]), ApiErrorCode::MetadataInvalid, 'metadata'],
    'metadata nested object' => [parserInput(['metadata' => ['n' => ['a' => 'b']]]), ApiErrorCode::MetadataInvalid, 'metadata'],
    'metadata value too long' => [parserInput(['metadata' => ['n' => str_repeat('v', 501)]]), ApiErrorCode::MetadataInvalid, 'metadata'],
    'client_reference_id too long' => [parserInput(['client_reference_id' => str_repeat('r', 201)]), ApiErrorCode::ParameterInvalid, 'client_reference_id'],
    'both expirations' => [parserInput(['expires_in_hours' => 5, 'expires_at' => '2030-01-01T00:00:00Z']), ApiErrorCode::ParameterInvalid, 'expires_at'],
    'expires_in_hours as string' => [parserInput(['expires_in_hours' => '5']), ApiErrorCode::ParameterInvalid, 'expires_in_hours'],
    'expires_in_hours zero' => [parserInput(['expires_in_hours' => 0]), ApiErrorCode::ExpirationOutOfRange, 'expires_in_hours'],
    'expires_at without time zone' => [parserInput(['expires_at' => '2030-01-01T00:00:00']), ApiErrorCode::ParameterInvalid, 'expires_at'],
    'fx not an object' => [parserInput(['fx' => 'banxico_fix']), ApiErrorCode::ParameterInvalid, 'fx'],
    'fx unknown mode' => [parserInput(['fx' => ['mode' => 'magic']]), ApiErrorCode::ParameterInvalid, 'fx.mode'],
    'fx fixed without rate' => [parserInput(['fx' => ['mode' => 'fixed']]), ApiErrorCode::ParameterMissing, 'fx.rate'],
    'fx rate as number' => [parserInput(['fx' => ['mode' => 'fixed', 'rate' => 17.25]]), ApiErrorCode::ParameterInvalid, 'fx.rate'],
    'fx rate with 7 decimals' => [parserInput(['fx' => ['mode' => 'fixed', 'rate' => '17.1234567']]), ApiErrorCode::ParameterInvalid, 'fx.rate'],
    'fx rate zero' => [parserInput(['fx' => ['mode' => 'fixed', 'rate' => '0']]), ApiErrorCode::ParameterInvalid, 'fx.rate'],
    'fx rate without fixed mode' => [parserInput(['fx' => ['mode' => 'banxico_fix', 'rate' => '17.00']]), ApiErrorCode::ParameterInvalid, 'fx.rate'],
    'unknown payer field' => [parserInput(['payer_fields' => ['shoe_size' => 'required']]), ApiErrorCode::PayerFieldInvalid, 'payer_fields.shoe_size'],
    'invalid payer requirement' => [parserInput(['payer_fields' => ['email' => 'mandatory']]), ApiErrorCode::PayerFieldInvalid, 'payer_fields.email'],
    'payer fields as a list' => [parserInput(['payer_fields' => ['email']]), ApiErrorCode::PayerFieldInvalid, 'payer_fields'],
    'return_url not a URL' => [parserInput(['return_url' => 'not a url']), ApiErrorCode::ParameterInvalid, 'return_url'],
    'return_url with credentials' => [parserInput(['return_url' => 'https://user:pass@shop.example.com/']), ApiErrorCode::ParameterInvalid, 'return_url'],
    'return_url javascript' => [parserInput(['return_url' => 'javascript:alert(1)']), ApiErrorCode::ParameterInvalid, 'return_url'],
    'return_url too long' => [parserInput(['return_url' => 'https://shop.example.com/'.str_repeat('a', 2048)]), ApiErrorCode::ParameterInvalid, 'return_url'],
    'unsupported locale' => [parserInput(['locale' => 'fr']), ApiErrorCode::ParameterInvalid, 'locale'],
    'pre_payment_validation as string' => [parserInput(['pre_payment_validation' => 'yes']), ApiErrorCode::ParameterInvalid, 'pre_payment_validation'],
]);

it('reports the first failing field in the documented order (ADR-0048 §6)', function (array $input, string $param): void {
    expect(parserError($input)->param)->toBe($param);
})->with([
    'amount before description' => [['amount' => 'x', 'currency' => 'USD'], 'amount'],
    'description before metadata' => [['amount' => '10.00', 'currency' => 'USD', 'metadata' => 'x'], 'description'],
    'metadata before expiration' => [parserInput(['metadata' => 'x', 'expires_in_hours' => 'x']), 'metadata'],
    'reference before fx' => [parserInput(['client_reference_id' => str_repeat('r', 201), 'fx' => 'x']), 'client_reference_id'],
    'expiration before fx' => [parserInput(['expires_in_hours' => 'x', 'fx' => 'x']), 'expires_in_hours'],
    'fx before payer fields' => [parserInput(['fx' => 'x', 'payer_fields' => 'x']), 'fx'],
    'payer fields before return URL' => [parserInput(['payer_fields' => 'x', 'return_url' => 'x']), 'payer_fields'],
    'unknown fields first of all' => [['nope' => 1, 'amount' => 1], 'nope'],
]);

it('accepts every field at its upper limit', function (): void {
    $metadata = [];

    foreach (range(1, 20) as $i) {
        $metadata[str_pad("k{$i}", 40, 'x')] = str_repeat('v', 500);
    }

    $data = app(PaymentLinkInputParser::class)->parse(parserInput([
        'description' => '   '.str_repeat('d', 500).'   ',
        'metadata' => $metadata,
        'client_reference_id' => str_repeat('r', 200),
        'expires_in_hours' => 1440,
        'fx' => ['mode' => 'fixed', 'rate' => '999999999999.999999'],
        'payer_fields' => ['email' => 'required', 'notes' => 'optional'],
        'return_url' => 'https://shop.example.com/'.str_repeat('a', 2048 - 25),
        'locale' => 'en',
        'pre_payment_validation' => false,
    ]));

    expect($data->description)->toBe(str_repeat('d', 500))
        ->and($data->metadata)->toHaveCount(20)
        ->and($data->clientReferenceId)->toHaveLength(200)
        ->and($data->expiresInHours)->toBe(1440)
        ->and($data->fxMode)->toBe(FxMode::Fixed)
        ->and($data->fxRate?->toString())->toBe('999999999999.999999')
        ->and($data->payerFields['email'])->toBe(PayerFieldRequirement::Required)
        ->and($data->returnUrl)->toHaveLength(2048)
        ->and($data->locale)->toBe(CheckoutLocale::En)
        ->and($data->prePaymentValidation)->toBeFalse();
});
