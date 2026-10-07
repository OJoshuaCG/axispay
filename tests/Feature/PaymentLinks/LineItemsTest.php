<?php

declare(strict_types=1);

use App\Modules\Fx\Enums\FxMode;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyDisplay;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\GatewayConnectionFactory;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\flushSession;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

/*
 * `line_items` of a payment link (spec B6, ADR-0064): the merchant's lines
 * [{label, amount, absorbs_rounding}] are display only. They must add up to
 * the link amount, are limited in number and label length, and when the link
 * can be converted exactly one of them takes the rounding residual. The
 * hosted page shows them in the order summary; the currency confirmation
 * shows them converted so that they add up to the MXN amount charged.
 */

/**
 * @param  array<array-key, mixed>  $body
 * @return TestResponse<Response>
 */
function createLinkWithLines(string $key, array $body): TestResponse
{
    return postJson(apiUrl('v1/payment_links'), $body, ApiTestHelpers::headers($key, 'idem-'.bin2hex(random_bytes(6))));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed> the pbx top-up of 10.00 USD: 12.30 USD in all
 */
function pbxBody(array $overrides = []): array
{
    return ApiTestHelpers::body([
        'amount' => '12.30',
        'line_items' => [
            ['label' => 'Balance top-up', 'amount' => '10.00'],
            ['label' => 'Processing charge', 'amount' => '1.50', 'absorbs_rounding' => true],
            ['label' => 'VAT', 'amount' => '0.80'],
        ],
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $settings  the tenant's settings document
 */
function lineItemsKey(array $settings = []): string
{
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    if ($settings !== []) {
        $tenant->forceFill(['settings' => $settings])->save();
    }

    return $key;
}

beforeEach(function (): void {
    travelTo('2026-10-09 18:00:00');
});

it('creates a link with line items and answers them in the link object', function (): void {
    $key = lineItemsKey();

    $response = createLinkWithLines($key, pbxBody());

    $response->assertCreated()
        ->assertJsonPath('amount', '12.30')
        ->assertJsonPath('line_items', [
            ['label' => 'Balance top-up', 'amount' => '10.00', 'absorbs_rounding' => false],
            ['label' => 'Processing charge', 'amount' => '1.50', 'absorbs_rounding' => true],
            ['label' => 'VAT', 'amount' => '0.80', 'absorbs_rounding' => false],
        ]);

    $id = $response->json('id');
    assert(is_string($id));
    $stored = ApiTestHelpers::freshLink(substr($id, 6))->line_items;

    expect($stored)->toBe([
        ['label' => 'Balance top-up', 'amount_minor' => 1_000, 'absorbs_rounding' => false],
        ['label' => 'Processing charge', 'amount_minor' => 150, 'absorbs_rounding' => true],
        ['label' => 'VAT', 'amount_minor' => 80, 'absorbs_rounding' => false],
    ]);
});

it('answers line_items null when the link has none', function (): void {
    $key = lineItemsKey();

    createLinkWithLines($key, ApiTestHelpers::body())->assertCreated()->assertJsonPath('line_items', null);
});

it('replays the same link for the same idempotency key and refuses the same key with other lines', function (): void {
    $key = lineItemsKey();
    $headers = ApiTestHelpers::headers($key, 'idem-lines-1');

    $first = postJson(apiUrl('v1/payment_links'), pbxBody(), $headers)->assertCreated();
    postJson(apiUrl('v1/payment_links'), pbxBody(), $headers)->assertCreated()->assertJsonPath('id', $first->json('id'));

    $other = pbxBody(['line_items' => [
        ['label' => 'Balance top-up', 'amount' => '10.30'],
        ['label' => 'Fee', 'amount' => '2.00', 'absorbs_rounding' => true],
    ]]);
    postJson(apiUrl('v1/payment_links'), $other, $headers)->assertStatus(ApiErrorCode::IdempotencyKeyReused->httpStatus());
});

it('rejects line items that are not valid', function (array $lineItems, ApiErrorCode $code, string $param): void {
    $key = lineItemsKey();

    $response = createLinkWithLines($key, pbxBody(['line_items' => $lineItems]));

    $response->assertStatus($code->httpStatus())
        ->assertJsonPath('error.code', $code->value)
        ->assertJsonPath('error.param', $param);
})->with([
    'not a list' => [['label' => 'x'], ApiErrorCode::ParameterInvalid, 'line_items'],
    'empty list' => [[], ApiErrorCode::ParameterInvalid, 'line_items'],
    'item is not an object' => [['a string'], ApiErrorCode::ParameterInvalid, 'line_items.0'],
    'sum differs from the amount' => [[['label' => 'Top-up', 'amount' => '10.00'], ['label' => 'VAT', 'amount' => '0.80', 'absorbs_rounding' => true]], ApiErrorCode::ParameterInvalid, 'line_items'],
    'missing label' => [[['amount' => '12.30', 'absorbs_rounding' => true]], ApiErrorCode::ParameterInvalid, 'line_items.0.label'],
    'blank label' => [[['label' => '   ', 'amount' => '12.30', 'absorbs_rounding' => true]], ApiErrorCode::ParameterInvalid, 'line_items.0.label'],
    'label of 101 characters' => [[['label' => str_repeat('a', 101), 'amount' => '12.30', 'absorbs_rounding' => true]], ApiErrorCode::ParameterInvalid, 'line_items.0.label'],
    'missing amount' => [[['label' => 'Top-up', 'absorbs_rounding' => true]], ApiErrorCode::ParameterMissing, 'line_items.0.amount'],
    'amount as a JSON number' => [[['label' => 'Top-up', 'amount' => 12.3, 'absorbs_rounding' => true]], ApiErrorCode::AmountMustBeString, 'line_items.0.amount'],
    'amount with too many decimals' => [[['label' => 'Top-up', 'amount' => '12.301', 'absorbs_rounding' => true]], ApiErrorCode::AmountInvalid, 'line_items.0.amount'],
    'zero amount' => [[['label' => 'Top-up', 'amount' => '12.30', 'absorbs_rounding' => true], ['label' => 'Free', 'amount' => '0.00']], ApiErrorCode::AmountInvalid, 'line_items.1.amount'],
    'absorbs_rounding is not a boolean' => [[['label' => 'Top-up', 'amount' => '12.30', 'absorbs_rounding' => 'yes']], ApiErrorCode::ParameterInvalid, 'line_items.0.absorbs_rounding'],
    'unknown key in an item' => [[['label' => 'Top-up', 'amount' => '12.30', 'absorbs_rounding' => true, 'sku' => 'x']], ApiErrorCode::ParameterInvalid, 'line_items.0.sku'],
    'two absorbing lines' => [[['label' => 'A', 'amount' => '6.15', 'absorbs_rounding' => true], ['label' => 'B', 'amount' => '6.15', 'absorbs_rounding' => true]], ApiErrorCode::ParameterInvalid, 'line_items'],
]);

it('accepts up to 20 lines and rejects 21', function (): void {
    $key = lineItemsKey();
    $lines = static fn (int $count): array => array_map(
        static fn (int $index): array => ['label' => 'Line '.$index, 'amount' => '1.00', 'absorbs_rounding' => $index === 1],
        range(1, $count),
    );

    createLinkWithLines($key, ApiTestHelpers::body(['amount' => '20.00', 'line_items' => $lines(20)]))->assertCreated();

    createLinkWithLines($key, ApiTestHelpers::body(['amount' => '21.00', 'line_items' => $lines(21)]))
        ->assertStatus(ApiErrorCode::ParameterInvalid->httpStatus())
        ->assertJsonPath('error.code', ApiErrorCode::ParameterInvalid->value)
        ->assertJsonPath('error.param', 'line_items');
});

it('accepts line amounts below the minimum charge of a whole payment', function (): void {
    $key = lineItemsKey();

    createLinkWithLines($key, ApiTestHelpers::body(['amount' => '10.10', 'line_items' => [
        ['label' => 'Top-up', 'amount' => '10.00'],
        ['label' => 'Tip', 'amount' => '0.10', 'absorbs_rounding' => true],
    ]]))->assertCreated();
});

it('needs exactly one absorbing line when the link can be converted', function (): void {
    $key = lineItemsKey(['fx' => ['conversion_enabled' => true, 'default_mode' => 'fixed', 'fixed_rate' => '20']]);
    $noFlag = [['label' => 'Top-up', 'amount' => '10.00'], ['label' => 'VAT', 'amount' => '2.30']];

    createLinkWithLines($key, pbxBody(['line_items' => $noFlag]))
        ->assertStatus(ApiErrorCode::ParameterInvalid->httpStatus())
        ->assertJsonPath('error.code', ApiErrorCode::ParameterInvalid->value)
        ->assertJsonPath('error.param', 'line_items');

    createLinkWithLines($key, pbxBody())->assertCreated();
});

it('does not need an absorbing line when the link cannot be converted', function (string $currency, array $fx): void {
    $key = lineItemsKey(['fx' => ['conversion_enabled' => true, 'default_mode' => 'fixed', 'fixed_rate' => '20']]);

    createLinkWithLines($key, ApiTestHelpers::body([
        'currency' => $currency,
        'amount' => '12.30',
        'fx' => $fx,
        'line_items' => [['label' => 'Top-up', 'amount' => '10.00'], ['label' => 'VAT', 'amount' => '2.30']],
    ]))->assertCreated();
})->with([
    'MXN link' => ['MXN', ['mode' => 'none']],
    'USD link that opts out of conversion' => ['USD', ['mode' => 'none']],
]);

/**
 * @param  array<string, mixed>  $link
 * @return array{0: Tenant, 1: PaymentLink}
 */
function linesScenario(array $link = [], string $account = 'MX', string $rate = '17.4225'): array
{
    $attributes = [
        'currency' => CurrencyCode::USD,
        'amount_minor' => 1_230,
        'fx_mode' => FxMode::Fixed,
        'line_items' => [
            ['label' => 'Balance top-up', 'amount_minor' => 1_000, 'absorbs_rounding' => false],
            ['label' => 'Processing charge & VAT', 'amount_minor' => 230, 'absorbs_rounding' => true],
        ],
        ...$link,
    ];

    [$tenant, $paymentLink] = Checkout::scenario(
        static fn (PaymentLinkFactory $factory): PaymentLinkFactory => $factory->state($attributes),
        static fn (GatewayConnectionFactory $factory): GatewayConnectionFactory => $factory->state(['country' => $account]),
    );
    $tenant->forceFill(['settings' => ['fx' => ['conversion_enabled' => true, 'fixed_rate' => $rate]]])->save();

    return [$tenant, $paymentLink];
}

it('shows the line items in the order summary of the hosted page, escaped', function (): void {
    [, $link] = linesScenario(account: 'US');

    $response = get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0']);

    $response->assertOk()
        ->assertSee('data-line-items', false)
        ->assertSee('Balance top-up')
        ->assertSee('Processing charge &amp; VAT', false)
        ->assertSee(MoneyDisplay::format(Money::ofMinor(1_000, CurrencyCode::USD)), false)
        ->assertSee(MoneyDisplay::format(Money::ofMinor(230, CurrencyCode::USD)), false);
});

it('shows no line list for a link without line items', function (): void {
    [, $link] = linesScenario(['line_items' => null], account: 'US');

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertDontSee('data-line-items', false);
});

it('keeps the lines off the informative pages, like the amount', function (): void {
    [, $link] = linesScenario(['status' => 'expired'], account: 'US');

    get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->assertDontSee('Balance top-up');

    [, $paid] = linesScenario(account: 'US');
    Checkout::pay($paid)->assertOk()->assertJson(['outcome' => 'paid']);
    get(payUrl('/l/'.$paid->public_token.'/complete'), ['User-Agent' => 'Mozilla/5.0'])->assertSee('Balance top-up');

    flushSession();
    get(payUrl('/l/'.$paid->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertDontSee('Balance top-up');
});

it('shows the converted lines on the currency confirmation, adding up to the MXN amount charged', function (): void {
    [, $link] = linesScenario();

    $response = Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'requires_currency_confirmation']);

    // 12.30 x 17.4225 = 214.30; 10.00 -> 174.23; the flagged line takes 214.30 - 174.23 = 40.07.
    $lines = $response->json('currency_confirmation.lines');
    $expected = [
        ['label' => 'Balance top-up', 'amount_label' => MoneyDisplay::format(Money::ofMinor(17_423, CurrencyCode::MXN)), 'amount_minor' => 17_423],
        ['label' => 'Processing charge & VAT', 'amount_label' => MoneyDisplay::format(Money::ofMinor(4_007, CurrencyCode::MXN)), 'amount_minor' => 4_007],
    ];

    expect($response->json('currency_confirmation.amount_minor'))->toBe(21_430)
        ->and($lines)->toBe($expected)
        ->and(array_sum(array_column((array) $lines, 'amount_minor')))->toBe(21_430);
});

it('answers the confirmation without lines for a link that has none', function (): void {
    [, $link] = linesScenario(['line_items' => null]);

    $response = Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'requires_currency_confirmation']);

    expect($response->json('currency_confirmation.lines'))->toBe([]);
});
