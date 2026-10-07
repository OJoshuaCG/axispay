<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Time\IsoDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\withHeaders;

/**
 * CRX-5 (spec B3, plan 10.6): `GET /v1/payments/{id}` and `GET /v1/payments`
 * (scope `payments:read`) so an integrator can fetch back a payment before it
 * acts on a webhook, including the `processing`, `requires_capture` and
 * `canceled` states pbx needs for its credit-before-capture saga.
 */

/**
 * @return TestResponse<Response>
 */
function getPayment(string $key, string $id): TestResponse
{
    return withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payments/'.$id));
}

/**
 * @return TestResponse<Response>
 */
function listPayments(string $key, string $query = ''): TestResponse
{
    return withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payments'.($query !== '' ? '?'.$query : '')));
}

/**
 * A payment attempt of `$link` written directly, in the tenant's context.
 *
 * @param  array<string, mixed>  $attributes
 */
function attemptOf(PaymentLink $link, PaymentAttemptStatus $status = PaymentAttemptStatus::Canceled, array $attributes = []): PaymentAttempt
{
    return Checkout::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus($status)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => Checkout::connectionOf($link)->id,
        ...$attributes,
    ]));
}

it('retrieves a captured payment with its link, reference, amount, card brand and country', function (): void {
    [$tenant, $link] = Checkout::scenario(static fn ($f) => $f->state(['client_reference_id' => 'ORDER-1029']));
    [, $key] = ApiTestHelpers::key($tenant);

    Checkout::pay($link)->assertOk();
    [$attempt] = Checkout::attempts($link);

    getPayment($key, $attempt->prefixedId())
        ->assertOk()
        ->assertJsonPath('id', $attempt->prefixedId())
        ->assertJsonPath('object', 'payment')
        ->assertJsonPath('livemode', false)
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('payment_link', $link->prefixedId())
        ->assertJsonPath('client_reference_id', 'ORDER-1029')
        ->assertJsonPath('amount', '1500.00')
        ->assertJsonPath('amount_minor', 150000)
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('fx', null)
        ->assertJsonPath('card', ['brand' => 'visa', 'country' => 'MX'])
        ->assertJsonPath('late_payment', false)
        ->assertJsonPath('failure_count', 0)
        ->assertJsonPath('failure', null)
        ->assertJsonPath('captured_at', IsoDateTime::format($attempt->refresh()->succeeded_at ?? throw new LogicException('The attempt did not succeed.')));
});

it('shows the statuses the integrator needs to decide', function (PaymentAttemptStatus $status, bool $hasCapturedAt): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);
    $attempt = attemptOf($link, $status, $status === PaymentAttemptStatus::Succeeded ? ['succeeded_at' => now()] : []);

    $response = getPayment($key, $attempt->prefixedId())->assertOk()->assertJsonPath('status', $status->value);

    expect($response->json('captured_at') !== null)->toBe($hasCapturedAt);
})->with([
    'processing' => [PaymentAttemptStatus::Processing, false],
    'authorized, waiting for capture' => [PaymentAttemptStatus::RequiresCapture, false],
    'canceled' => [PaymentAttemptStatus::Canceled, false],
    'succeeded' => [PaymentAttemptStatus::Succeeded, true],
    'failed' => [PaymentAttemptStatus::Failed, false],
]);

it('shows the card as null brand and country before a card was read', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $attempt = attemptOf(ApiTestHelpers::link($tenant), PaymentAttemptStatus::RequiresPaymentMethod);

    getPayment($key, $attempt->prefixedId())->assertOk()->assertJsonPath('card', ['brand' => null, 'country' => null]);
});

it('never shows gateway identifiers, last four digits, fingerprints or payer data', function (): void {
    [$tenant, $link] = Checkout::scenario();
    [, $key] = ApiTestHelpers::key($tenant);
    Checkout::pay($link)->assertOk();
    [$attempt] = Checkout::attempts($link);

    $body = getPayment($key, $attempt->prefixedId())->assertOk()->getContent();
    $list = listPayments($key)->assertOk()->getContent();

    foreach ([$body, $list] as $raw) {
        expect($raw)->not->toContain((string) $attempt->provider_payment_id)
            ->and($raw)->not->toContain('4242')
            ->and($raw)->not->toContain('fp_fake')
            ->and($raw)->not->toContain('ana@example.com')
            ->and($raw)->not->toContain('last4')
            ->and($raw)->not->toContain('fingerprint')
            ->and($raw)->not->toContain('client_ip');
    }
});

it('answers 404 for a wrong prefix, a bare ULID or an unknown ID', function (string $id): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    getPayment($key, $id)
        ->assertNotFound()
        ->assertJsonPath('error.code', ApiErrorCode::ResourceNotFound->value);
})->with([
    'plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    '01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    'pay_01J8Z3Q6T4Y0V8KX2M1N5P7R9S',
    'pay_01j8z3q6t4y0v8kx2m1n5p7r9s',
]);

it('answers 404 for another tenant\'s payment and for the other mode\'s payment', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $other = ApiTestHelpers::readyTenant();
    $foreign = attemptOf(ApiTestHelpers::link($other));

    getPayment($key, $foreign->prefixedId())->assertNotFound()->assertJsonPath('error.code', 'resource_not_found');

    // A live key cannot read a test-mode payment of its own tenant.
    GatewayTestHelpers::connection($tenant, true);
    [, $liveKey] = ApiTestHelpers::key($tenant, livemode: true);
    $own = attemptOf(ApiTestHelpers::link($tenant));

    getPayment($liveKey, $own->prefixedId())->assertNotFound()->assertJsonPath('error.code', 'resource_not_found');
    getPayment($key, $own->prefixedId())->assertOk();
});

it('requires the payments:read scope', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $withoutScope] = ApiTestHelpers::key($tenant, scopes: [ApiScope::LinksRead, ApiScope::EventsRead]);
    [, $withScope] = ApiTestHelpers::key($tenant, scopes: [ApiScope::PaymentsRead]);
    $attempt = attemptOf(ApiTestHelpers::link($tenant));

    getPayment($withoutScope, $attempt->prefixedId())->assertForbidden()->assertJsonPath('error.code', ApiErrorCode::InsufficientScope->value);
    listPayments($withoutScope)->assertForbidden()->assertJsonPath('error.code', ApiErrorCode::InsufficientScope->value);
    getPayment($withScope, $attempt->prefixedId())->assertOk();
    listPayments($withScope)->assertOk();
});

it('refuses a request without an API key', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $attempt = attemptOf(ApiTestHelpers::link($tenant));

    withHeaders(['Accept' => 'application/json'])->getJson(apiUrl('v1/payments/'.$attempt->prefixedId()))->assertUnauthorized();
    withHeaders(['Accept' => 'application/json'])->getJson(apiUrl('v1/payments'))->assertUnauthorized();
});

it('lists the payments of a link, newest first, with has_more and cursors', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);
    $otherLink = ApiTestHelpers::link($tenant);

    Carbon::setTestNow('2026-09-26 10:00:00');
    $attempts = [];

    foreach (range(1, 3) as $i) {
        Carbon::setTestNow(now()->addSecond());
        $attempts[] = attemptOf($link);
    }

    attemptOf($otherLink);

    $page = listPayments($key, 'payment_link='.$link->prefixedId().'&limit=2')->assertOk()->assertJsonPath('object', 'list')->assertJsonPath('has_more', true);
    expect(ApiTestHelpers::listed($page))->toBe([$attempts[2]->prefixedId(), $attempts[1]->prefixedId()]);

    $next = listPayments($key, 'payment_link='.$link->prefixedId().'&limit=2&starting_after='.$attempts[1]->prefixedId())->assertJsonPath('has_more', false);
    expect(ApiTestHelpers::listed($next))->toBe([$attempts[0]->prefixedId()]);

    $previous = listPayments($key, 'payment_link='.$link->prefixedId().'&limit=1&ending_before='.$attempts[0]->prefixedId())->assertJsonPath('has_more', true);
    expect(ApiTestHelpers::listed($previous))->toBe([$attempts[1]->prefixedId()]);

    // Without the filter, every payment of the tenant and mode.
    expect(listPayments($key)->json('data'))->toHaveCount(4);
});

it('filters the list by status and creation date', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);

    Carbon::setTestNow('2026-09-20 12:00:00');
    $old = attemptOf($link, PaymentAttemptStatus::Canceled);
    Carbon::setTestNow('2026-09-25 12:00:00');
    $processing = attemptOf($link, PaymentAttemptStatus::Processing);

    expect(ApiTestHelpers::listed(listPayments($key, 'status=processing')))->toBe([$processing->prefixedId()])
        ->and(ApiTestHelpers::listed(listPayments($key, 'status=canceled')))->toBe([$old->prefixedId()])
        ->and(ApiTestHelpers::listed(listPayments($key, 'created[lte]=2026-09-21T00:00:00Z')))->toBe([$old->prefixedId()])
        ->and(ApiTestHelpers::listed(listPayments($key, 'created[gte]='.Carbon::parse('2026-09-24 00:00:00')->getTimestamp())))->toBe([$processing->prefixedId()]);
});

it('lists nothing for a link of another tenant and answers 400 for a malformed link filter', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $foreignLink = ApiTestHelpers::link(ApiTestHelpers::readyTenant());

    listPayments($key, 'payment_link='.$foreignLink->prefixedId())->assertOk()->assertJsonCount(0, 'data');
    listPayments($key, 'payment_link=pay_01J8Z3Q6T4Y0V8KX2M1N5P7R9S')->assertStatus(400)->assertJsonPath('error.param', 'payment_link');
});

it('rejects invalid list parameters', function (string $query, string $param): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);

    listPayments($key, $query)
        ->assertStatus(400)
        ->assertJsonPath('error.code', ApiErrorCode::ParameterInvalid->value)
        ->assertJsonPath('error.param', $param);
})->with([
    ['limit=0', 'limit'],
    ['limit=101', 'limit'],
    ['status=open', 'status'],
    ['starting_after=plink_01J8Z3Q6T4Y0V8KX2M1N5P7R9S', 'starting_after'],
    ['starting_after=pay_01J8Z3Q6T4Y0V8KX2M1N5P7R9S&ending_before=pay_01J8Z3Q6T4Y0V8KX2M1N5P7R9T', 'ending_before'],
    ['created[gte]=yesterday', 'created[gte]'],
    ['created=5', 'created'],
]);

it('never lists another tenant\'s payments', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $other = ApiTestHelpers::readyTenant();
    attemptOf(ApiTestHelpers::link($other));
    $own = attemptOf(ApiTestHelpers::link($tenant));

    expect(ApiTestHelpers::listed(listPayments($key)))->toBe([$own->prefixedId()])
        ->and(PaymentAttempt::query()->withoutGlobalScopes()->count())->toBe(2);
});
