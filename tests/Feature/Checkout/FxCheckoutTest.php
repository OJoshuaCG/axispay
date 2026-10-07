<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Fx\Enums\FxMode;
use App\Modules\Fx\Models\FxQuote;
use App\Modules\Fx\Models\StoredExchangeRate;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyDisplay;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Webhooks\Models\WebhookEvent;
use Database\Factories\GatewayConnectionFactory;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\FakeHostResolver;
use Tests\Support\FakePaymentGateway;
use Tests\Support\ValidationTestHelpers as Validation;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;
use function Pest\Laravel\withHeaders;

/**
 * Currency conversion at the checkout (plan 13, ADR-009, ADR-0063): a card
 * issued in Mexico paying a USD link of a Mexican account is converted to MXN
 * after the payer confirmed the exact amount; every other combination is
 * charged as it is, or refused when the merchant cannot convert. Worked
 * example of the pbx tenant: 12.30 USD x 20 = 246.00 MXN.
 */

/** The `fx` block of a payment converted with the tenant's fixed rate of 20 (12.30 USD to 246.00 MXN). */
const FIXED_20_FX_BLOCK = [
    'applied' => true,
    'mode' => 'fixed',
    'source' => 'merchant',
    'rate' => '20.000000',
    'rate_date' => null,
    'markup_bps' => 0,
    'effective_rate' => '20.000000',
    'original_amount' => '12.30',
    'original_currency' => 'USD',
];

/**
 * @param  array<mixed>  $fx  tenants.settings.fx
 * @param  array<mixed>  $link  link attributes
 * @return array{0: Tenant, 1: PaymentLink, 2: FakePaymentGateway}
 */
function fxCheckout(array $fx = ['conversion_enabled' => true, 'fixed_rate' => '20'], array $link = [], string $account = 'MX', int $amountMinor = 1_230, FxMode $mode = FxMode::Fixed): array
{
    $attributes = ['currency' => CurrencyCode::USD, 'amount_minor' => $amountMinor, 'fx_mode' => $mode];

    foreach ($link as $key => $value) {
        $attributes[(string) $key] = $value;
    }

    [$tenant, $paymentLink, $fake] = Checkout::scenario(
        static fn (PaymentLinkFactory $factory): PaymentLinkFactory => $factory->state($attributes),
        static fn (GatewayConnectionFactory $factory): GatewayConnectionFactory => $factory->state(['country' => $account]),
    );
    $tenant->forceFill(['settings' => ['fx' => $fx]])->save();

    return [$tenant, $paymentLink, $fake];
}

/**
 * @return list<FxQuote>
 */
function quotesOf(PaymentLink $link): array
{
    return Checkout::inTenant($link, static fn (): array => array_values(FxQuote::query()->where('payment_link_id', $link->id)->orderBy('id')->get()->all()));
}

function fxAttempt(PaymentLink $link): PaymentAttempt
{
    return Checkout::attempts($link)[0] ?? throw new LogicException('No attempt.');
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed> the `currency_confirmation` of an answer
 */
function confirmationOf(TestResponse $response): array
{
    $confirmation = $response->json('currency_confirmation');

    if (! is_array($confirmation)) {
        throw new LogicException('The answer has no currency confirmation.');
    }

    $typed = [];

    foreach ($confirmation as $key => $value) {
        $typed[(string) $key] = $value;
    }

    return $typed;
}

/**
 * @param  TestResponse<Response>  $response
 */
function quoteIdOf(TestResponse $response): string
{
    $id = confirmationOf($response)['quote_id'] ?? null;

    return is_string($id) ? $id : throw new LogicException('The confirmation has no quote id.');
}

function fxFix(string $rate, string $date): StoredExchangeRate
{
    $row = new StoredExchangeRate;
    $row->forceFill(['source' => 'banxico_fix', 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => $rate, 'rate_date' => $date, 'fetched_at' => now(), 'requires_review' => false])->save();

    return $row;
}

beforeEach(function (): void {
    travelTo('2026-10-07 18:00:00');
    config(['axispay.checkout.turnstile_after_failures' => 99]);
});

it('asks the payer to confirm the converted amount before any charge: 12.30 USD x 20 = 246.00 MXN', function (): void {
    [, $link, $fake] = fxCheckout();

    $response = Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'requires_currency_confirmation']);
    $confirmation = confirmationOf($response);
    [$quote] = quotesOf($link);

    expect($confirmation['quote_id'])->toBe($quote->id)
        ->and($confirmation['original_label'])->toBe(MoneyDisplay::format(Money::ofMinor(1_230, CurrencyCode::USD)))
        ->and($confirmation['amount_label'])->toBe(MoneyDisplay::format(Money::ofMinor(24_600, CurrencyCode::MXN)))
        ->and($confirmation['amount_minor'])->toBe(24_600)
        ->and($confirmation['currency'])->toBe('MXN')
        ->and($confirmation['rate_text'])->toContain('20')
        ->and($confirmation['rate_text'])->toContain('comercio')
        ->and($confirmation['markup_text'])->toBeNull()
        ->and($confirmation['pay_label'])->toContain($confirmation['amount_label'])
        ->and($quote->converted_amount_minor)->toBe(24_600)
        // Nothing reached the gateway but the card read, and no attempt exists yet.
        ->and(Checkout::attempts($link))->toBe([])
        ->and($fake->callsTo('createOrUpdatePayment'))->toBe([])
        ->and($fake->callsTo('confirmPayment'))->toBe([])
        ->and(Checkout::freshLink($link)->status->value)->toBe('active');
});

it('charges the confirmed MXN amount and records the original and the charged amounts with the quote', function (): void {
    [, $link, $fake] = fxCheckout();
    $quoteId = quoteIdOf(Checkout::pay($link));

    Checkout::pay($link, 'ctoken_success', ['fx_quote_id' => $quoteId, 'currency_confirmed' => true])->assertOk()->assertJson(['outcome' => 'paid']);

    $attempt = fxAttempt($link);

    expect($attempt->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempt->amount_minor)->toBe(24_600)
        ->and($attempt->currency)->toBe(CurrencyCode::MXN)
        ->and($attempt->original_amount_minor)->toBe(1_230)
        ->and($attempt->original_currency)->toBe(CurrencyCode::USD)
        ->and($attempt->fx_quote_id)->toBe($quoteId)
        ->and($attempt->card_country)->toBe('MX')
        ->and($fake->chargeOf((string) $attempt->provider_payment_id))->toBe(['amount' => 24_600, 'currency' => 'MXN'])
        ->and(quotesOf($link))->toHaveCount(1);
});

it('does not charge without the explicit confirmation flag, even with a valid quote', function (mixed $confirmed): void {
    [, $link] = fxCheckout();
    $quoteId = quoteIdOf(Checkout::pay($link));

    Checkout::pay($link, 'ctoken_success', ['fx_quote_id' => $quoteId, 'currency_confirmed' => $confirmed])
        ->assertOk()
        ->assertJson(['outcome' => 'requires_currency_confirmation', 'currency_confirmation' => ['quote_id' => $quoteId]]);

    expect(Checkout::attempts($link))->toBe([])->and(quotesOf($link))->toHaveCount(1);
})->with(['missing' => [null], 'false' => [false], 'string true' => ['true'], 'one' => [1]]);

it('ignores a quote id that is not this link\'s and asks again with a fresh quote of this link', function (): void {
    [, $link] = fxCheckout();
    [, $other] = fxCheckout();
    $foreignQuoteId = quoteIdOf(Checkout::pay($other));

    $response = Checkout::pay($link, 'ctoken_success', ['fx_quote_id' => $foreignQuoteId, 'currency_confirmed' => true])
        ->assertOk()
        ->assertJson(['outcome' => 'requires_currency_confirmation']);

    expect($response->json('currency_confirmation.quote_id'))->not->toBe($foreignQuoteId)
        ->and(Checkout::attempts($link))->toBe([]);

    Checkout::pay($link, 'ctoken_success', ['fx_quote_id' => 'not-a-ulid', 'currency_confirmed' => true])->assertOk()->assertJson(['outcome' => 'requires_currency_confirmation']);
});

it('charges a card of another country in USD, with no quote', function (): void {
    [, $link, $fake] = fxCheckout();

    Checkout::pay($link, 'ctoken_success_us')->assertOk()->assertJson(['outcome' => 'paid']);

    $attempt = fxAttempt($link);

    expect($attempt->amount_minor)->toBe(1_230)
        ->and($attempt->currency)->toBe(CurrencyCode::USD)
        ->and($attempt->fx_quote_id)->toBeNull()
        ->and($attempt->card_country)->toBe('US')
        ->and(quotesOf($link))->toBe([])
        ->and($fake->chargeOf((string) $attempt->provider_payment_id))->toBe(['amount' => 1_230, 'currency' => 'USD']);
});

it('charges a Mexican card in USD when the connected account is not Mexican', function (): void {
    [, $link] = fxCheckout(account: 'US');

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);

    expect(fxAttempt($link)->currency)->toBe(CurrencyCode::USD)->and(quotesOf($link))->toBe([]);
});

it('charges an MXN link as it is, whatever the card', function (): void {
    [, $link] = fxCheckout(link: ['currency' => CurrencyCode::MXN, 'amount_minor' => 50_000, 'fx_mode' => FxMode::None]);

    Checkout::pay($link)->assertOk()->assertJson(['outcome' => 'paid']);

    expect(fxAttempt($link)->amount_minor)->toBe(50_000)->and(fxAttempt($link)->currency)->toBe(CurrencyCode::MXN);
});

it('refuses with the merchant name, charging nothing, when the merchant cannot convert (plan 13.2)', function (array $fx, array $link): void {
    [, $paymentLink, $fake] = fxCheckout($fx, $link);

    Checkout::pay($paymentLink)
        ->assertStatus(422)
        ->assertJson(['outcome' => 'conversion_unavailable'])
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Tienda Demo'));

    expect(Checkout::attempts($paymentLink))->toBe([])
        ->and(quotesOf($paymentLink))->toBe([])
        ->and($fake->callsTo('createOrUpdatePayment'))->toBe([])
        ->and(Checkout::freshLink($paymentLink)->status->value)->toBe('active');
})->with([
    'tenant conversion off' => [['conversion_enabled' => false, 'fixed_rate' => '20'], []],
    'link opted out' => [['conversion_enabled' => true, 'fixed_rate' => '20'], ['fx_mode' => FxMode::None]],
]);

it('records conversion_unavailable for the tenant, once per link and hour, without card data', function (): void {
    [, $link] = fxCheckout(['conversion_enabled' => false]);

    Checkout::pay($link)->assertStatus(422);
    Checkout::pay($link)->assertStatus(422);

    $entries = Checkout::inTenant($link, static fn () => AuditLog::query()->where('action', 'checkout.conversion_unavailable')->get());

    expect($entries)->toHaveCount(1)
        ->and($entries->sole()->subject_id)->toBe($link->id)
        ->and(json_encode($entries->sole()->changes))->not->toContain('4242');

    travelTo(now()->addHours(2));
    Checkout::pay($link)->assertStatus(422);

    expect(Checkout::inTenant($link, static fn () => AuditLog::query()->where('action', 'checkout.conversion_unavailable')->count()))->toBe(2);
});

it('answers conversion_unavailable when the fixed mode has no rate at all', function (): void {
    [, $link] = fxCheckout(['conversion_enabled' => true]);

    Checkout::pay($link)->assertStatus(422)->assertJson(['outcome' => 'conversion_unavailable']);
    expect(Checkout::attempts($link))->toBe([]);
});

it('answers conversion_unavailable when the converted amount is below the MXN minimum', function (): void {
    [, $link] = fxCheckout(['conversion_enabled' => true, 'fixed_rate' => '17'], amountMinor: 50);

    Checkout::pay($link)->assertStatus(422)->assertJson(['outcome' => 'conversion_unavailable']);
});

it('converts with the stored Banxico FIX and the tenant markup, and shows the source, the date and the markup', function (): void {
    fxFix('17.2500', '2026-10-07');
    [, $link] = fxCheckout(['conversion_enabled' => true, 'markup_bps' => 100, 'quote_validity_minutes' => 30], amountMinor: 150_000, mode: FxMode::BanxicoFix);

    $confirmation = confirmationOf(Checkout::pay($link)->assertOk());
    [$quote] = quotesOf($link);

    expect($quote->source)->toBe('banxico_fix')
        ->and($quote->effective_rate)->toBe('17.422500')
        ->and($confirmation['amount_minor'])->toBe(2_613_375)
        ->and($confirmation['rate_text'])->toContain('17.4225')
        ->and($confirmation['rate_text'])->toContain('Banxico')
        ->and($confirmation['rate_text'])->toContain('07/10/2026')
        ->and($confirmation['markup_text'])->toContain('1.00')
        ->and($quote->expires_at->equalTo(now()->addMinutes(30)->toImmutable()))->toBeTrue();
});

it('blocks banxico_fix with a stale or missing FIX: never charges a stale rate', function (?string $date): void {
    if ($date !== null) {
        fxFix('17.2500', $date);
    }

    [, $link] = fxCheckout(['conversion_enabled' => true], mode: FxMode::BanxicoFix);

    Checkout::pay($link)->assertStatus(422)->assertJson(['outcome' => 'conversion_unavailable']);
    expect(Checkout::attempts($link))->toBe([]);
})->with(['five days old' => ['2026-10-02'], 'none stored' => [null]]);

it('proceeds with a fresh quote when the old one expired and the amount did not change, and asks again when it did', function (): void {
    fxFix('17.2500', '2026-10-07');
    [, $link] = fxCheckout(['conversion_enabled' => true, 'quote_validity_minutes' => 30], amountMinor: 150_000, mode: FxMode::BanxicoFix);
    $firstId = quoteIdOf(Checkout::pay($link));

    // 31 minutes later, same FIX: a new quote with the same amount charges at once.
    travelTo(now()->addMinutes(31));
    Checkout::pay($link, 'ctoken_success', ['fx_quote_id' => $firstId, 'currency_confirmed' => true])->assertOk()->assertJson(['outcome' => 'paid']);

    $quotes = quotesOf($link);
    expect($quotes)->toHaveCount(2)
        ->and(fxAttempt($link)->fx_quote_id)->toBe($quotes[1]->id)
        ->and(fxAttempt($link)->amount_minor)->toBe(2_587_500);
});

it('asks again when the expired quote is replaced by a different amount', function (): void {
    fxFix('17.2500', '2026-10-07');
    [, $link] = fxCheckout(['conversion_enabled' => true, 'quote_validity_minutes' => 30], amountMinor: 150_000, mode: FxMode::BanxicoFix);
    $firstId = quoteIdOf(Checkout::pay($link));

    travelTo(now()->addMinutes(31));
    fxFix('17.5000', '2026-10-08');
    $response = Checkout::pay($link, 'ctoken_success', ['fx_quote_id' => $firstId, 'currency_confirmed' => true])->assertOk();

    expect($response->json('outcome'))->toBe('requires_currency_confirmation')
        ->and($response->json('currency_confirmation.amount_minor'))->toBe(2_625_000)
        ->and($response->json('currency_confirmation.quote_id'))->not->toBe($firstId)
        ->and(Checkout::attempts($link))->toBe([]);
});

it('switches the same attempt back to USD when the payer retries with a foreign card after a declined Mexican one', function (): void {
    [, $link, $fake] = fxCheckout();
    $quoteId = quoteIdOf(Checkout::pay($link));
    Checkout::pay($link, 'ctoken_decline', ['fx_quote_id' => $quoteId, 'currency_confirmed' => true])->assertStatus(402);

    $declined = fxAttempt($link);
    expect($declined->amount_minor)->toBe(24_600)->and($declined->fx_quote_id)->toBe($quoteId);

    Checkout::pay($link, 'ctoken_success_us')->assertOk()->assertJson(['outcome' => 'paid']);

    $attempt = fxAttempt($link);

    expect(Checkout::attempts($link))->toHaveCount(1)
        ->and($attempt->id)->toBe($declined->id)
        ->and($attempt->amount_minor)->toBe(1_230)
        ->and($attempt->currency)->toBe(CurrencyCode::USD)
        ->and($attempt->original_amount_minor)->toBe(1_230)
        ->and($attempt->fx_quote_id)->toBeNull()
        ->and($fake->chargeOf((string) $attempt->provider_payment_id))->toBe(['amount' => 1_230, 'currency' => 'USD']);
});

it('sends the applied FX block, with the converted charge, in the pre-payment validation body', function (): void {
    FakeHostResolver::install();
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    [$tenant, $link] = fxCheckout(link: ['pre_payment_validation' => true]);
    Validation::endpoint($tenant);
    $quoteId = quoteIdOf(Checkout::pay($link));

    Checkout::pay($link, 'ctoken_success', ['fx_quote_id' => $quoteId, 'currency_confirmed' => true])->assertOk()->assertJson(['outcome' => 'paid']);

    $payload = jsonArray(Validation::sentRequests()[0]->body());

    expect(data_get($payload, 'data.charge.amount'))->toBe('246.00')
        ->and(data_get($payload, 'data.charge.currency'))->toBe('MXN')
        ->and(data_get($payload, 'data.charge.fx'))->toBe(FIXED_20_FX_BLOCK)
        ->and(data_get($payload, 'data.payment_link.amount'))->toBe('12.30')
        ->and(data_get($payload, 'data.payment_link.currency'))->toBe('USD');
});

it('keeps the pre-payment validation fx block minimal when no conversion applied', function (): void {
    FakeHostResolver::install();
    Http::fake(['*' => Validation::answer(['decision' => 'approve'])]);
    [$tenant, $link] = fxCheckout(link: ['pre_payment_validation' => true]);
    Validation::endpoint($tenant);

    Checkout::pay($link, 'ctoken_success_us')->assertOk();

    expect(data_get(jsonArray(Validation::sentRequests()[0]->body()), 'data.charge.fx'))->toBe(['applied' => false]);
});

it('reports the applied FX in payment.succeeded, in payment_link.paid and in GET /v1/payments, null otherwise', function (): void {
    [$tenant, $link] = fxCheckout();
    $quoteId = quoteIdOf(Checkout::pay($link));
    Checkout::pay($link, 'ctoken_success', ['fx_quote_id' => $quoteId, 'currency_confirmed' => true])->assertOk();

    $events = WebhookTestHelpers::in($link->tenant_id, $link->livemode, static fn (): array => WebhookEvent::query()->orderBy('id')->get()->all());
    $bodies = [];

    foreach ($events as $event) {
        $bodies[$event->type->value] = jsonArray($event->payload);
    }

    $expected = FIXED_20_FX_BLOCK;

    $succeeded = $bodies['payment.succeeded'] ?? throw new LogicException('No payment.succeeded event.');
    $paid = $bodies['payment_link.paid'] ?? throw new LogicException('No payment_link.paid event.');

    expect(data_get($succeeded, 'data.object.fx'))->toBe($expected)
        ->and(data_get($succeeded, 'data.object.amount'))->toBe('246.00')
        ->and(data_get($succeeded, 'data.object.currency'))->toBe('MXN')
        ->and(data_get($paid, 'data.payment.fx'))->toBe($expected);

    [, $key] = ApiTestHelpers::key($tenant);
    $attempt = fxAttempt($link);

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payments/'.$attempt->prefixedId()))
        ->assertOk()
        ->assertJsonPath('amount', '246.00')
        ->assertJsonPath('currency', 'MXN')
        ->assertJsonPath('fx', $expected);

    // A payment with no conversion keeps `fx: null` (the key stays in the contract).
    [$otherTenant, $usLink] = fxCheckout();
    Checkout::pay($usLink, 'ctoken_success_us')->assertOk();
    [, $otherKey] = ApiTestHelpers::key($otherTenant);

    withHeaders(ApiTestHelpers::headers($otherKey))->getJson(apiUrl('v1/payments/'.fxAttempt($usLink)->prefixedId()))->assertOk()->assertJsonPath('fx', null);
});

it('shows the FX legend under the total when a Mexican card would be converted, with the exact MXN amount', function (): void {
    [, $link] = fxCheckout();

    $html = get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent();

    expect($html)->toContain('data-fx-legend')
        ->toContain('Si pagas con una tarjeta emitida en México')
        ->toContain('246.00')
        ->toContain('MXN')
        ->toContain('tipo de cambio definido por el comercio')
        // The confirmation panel is in the page but hidden until the server asks for it.
        ->toMatch('/data-fx-confirmation[^>]*hidden/');
    // Nothing was stored just to render the page: the legend is an estimate.
    expect(quotesOf($link))->toBe([]);
});

it('shows the Banxico source, the date and the markup in the legend', function (): void {
    fxFix('17.2500', '2026-10-07');
    [, $link] = fxCheckout(['conversion_enabled' => true, 'markup_bps' => 250], amountMinor: 150_000, mode: FxMode::BanxicoFix);

    $html = get(payUrl('/l/'.$link->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent();

    expect($html)->toContain('Banxico FIX del 07/10/2026')->toContain('2.50')->toContain('ajuste del comercio');
});

it('shows no FX legend when nothing would be converted, or when no rate can be quoted', function (string $case): void {
    [$fx, $link, $account, $mode] = match ($case) {
        'tenant off' => [['conversion_enabled' => false, 'fixed_rate' => '20'], [], 'MX', FxMode::Fixed],
        'link opted out' => [['conversion_enabled' => true, 'fixed_rate' => '20'], ['fx_mode' => FxMode::None], 'MX', FxMode::None],
        'MXN link' => [['conversion_enabled' => true, 'fixed_rate' => '20'], ['currency' => CurrencyCode::MXN, 'fx_mode' => FxMode::None], 'MX', FxMode::None],
        'US account' => [['conversion_enabled' => true, 'fixed_rate' => '20'], [], 'US', FxMode::Fixed],
        'no fixed rate' => [['conversion_enabled' => true], [], 'MX', FxMode::Fixed],
        'no banxico rate' => [['conversion_enabled' => true], [], 'MX', FxMode::BanxicoFix],
        default => throw new LogicException("Unknown case {$case}."),
    };
    [, $paymentLink] = fxCheckout($fx, $link, $account, mode: $mode);

    $html = get(payUrl('/l/'.$paymentLink->public_token), ['User-Agent' => 'Mozilla/5.0'])->assertOk()->getContent();

    expect($html)->not->toContain('data-fx-legend');
})->with(['tenant off', 'link opted out', 'MXN link', 'US account', 'no fixed rate', 'no banxico rate']);

it('treats the sandbox cards as Mexican, with a foreign scenario charged in USD', function (): void {
    config(['axispay.checkout.sandbox' => true]);
    [$tenant, $link] = fxCheckout();
    // The sandbox gateway instead of the fake one.
    app()->instance(GatewayFactory::class, new GatewayFactory(app()));

    $quoteId = quoteIdOf(Checkout::pay($link, 'ctoken_sandbox_success_abc123')->assertOk()->assertJson(['outcome' => 'requires_currency_confirmation']));
    Checkout::pay($link, 'ctoken_sandbox_success_abc123', ['fx_quote_id' => $quoteId, 'currency_confirmed' => true])->assertOk()->assertJson(['outcome' => 'paid']);

    expect(fxAttempt($link)->currency)->toBe(CurrencyCode::MXN)->and(fxAttempt($link)->amount_minor)->toBe(24_600);

    [, $foreignLink] = fxCheckout();
    app()->instance(GatewayFactory::class, new GatewayFactory(app()));

    Checkout::pay($foreignLink, 'ctoken_sandbox_foreign_abc123')->assertOk()->assertJson(['outcome' => 'paid']);

    expect(fxAttempt($foreignLink)->currency)->toBe(CurrencyCode::USD)
        ->and(fxAttempt($foreignLink)->card_country)->toBe('US')
        ->and(fxAttempt($foreignLink)->fx_quote_id)->toBeNull();
});
