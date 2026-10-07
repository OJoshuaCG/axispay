<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Enums\ProviderRefundStatus;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Modules\Webhooks\Enums\WebhookEventType;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\RefundTestHelpers as Refunds;

use function Pest\Laravel\withHeaders;

/**
 * CRX-13 (spec B10, plan 10.7 and 16.1): `POST /v1/refunds`, `GET /v1/refunds`
 * and `GET /v1/refunds/{id}`. A refund is full or partial, in the currency
 * that was charged, always on the gateway connection the payment was made
 * with, and never above what is left to refund (pending refunds count). The
 * integrator decides what a refund means for its own business: this API only
 * administers the payment (spec P0).
 */
it('refunds the whole payment when no amount is sent', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'reason' => 'requested_by_customer'])
        ->assertCreated()
        ->assertJsonPath('object', 'refund')
        ->assertJsonPath('payment', $attempt->prefixedId())
        ->assertJsonPath('livemode', false)
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('amount', '1500.00')
        ->assertJsonPath('amount_minor', 150000)
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('reason', 'requested_by_customer')
        ->assertJsonPath('origin', 'api')
        ->assertJsonPath('failure', null);

    $refunds = Refunds::of($link);

    expect($refunds)->toHaveCount(1)
        ->and($refunds[0]->status)->toBe(RefundState::Succeeded)
        ->and($refunds[0]->provider_refund_id)->not->toBeNull()
        ->and($fake->callsTo('refund'))->toHaveCount(1)
        ->and(Refunds::attempt($link)->amount_refunded_minor)->toBe(150000)
        ->and(Checkout::freshLink($link)->refund_status->value)->toBe('full');
});

it('refunds part of a payment and then the rest, and refuses a third refund', function (): void {
    [$link, , $attempt, $key] = Refunds::scenario();

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '500.00'], 'k-1')->assertCreated()->assertJsonPath('amount_minor', 50000);
    expect(Checkout::freshLink($link)->refund_status->value)->toBe('partial')
        ->and(Refunds::attempt($link)->amount_refunded_minor)->toBe(50000);

    Refunds::post($key, ['payment' => $attempt->prefixedId()], 'k-2')->assertCreated()->assertJsonPath('amount', '1000.00');
    expect(Checkout::freshLink($link)->refund_status->value)->toBe('full');

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '0.01'], 'k-3')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'refund_exceeds_available');
});

it('refuses an amount above what is left to refund, and tells how much is left', function (): void {
    [, , $attempt, $key] = Refunds::scenario();

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '1500.01'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'refund_exceeds_available')
        ->assertJsonPath('error.param', 'amount');

    expect(Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '1500.01'], 'other-key')->json('error.message'))->toContain('1500.00');
});

it('counts refunds still pending against what is left to refund', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->refundsAs(ProviderRefundStatus::Pending);

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '1000.00'], 'k-1')->assertCreated()->assertJsonPath('status', 'pending');

    // Pending: not refunded yet, so the payment is not marked refunded...
    expect(Refunds::attempt($link)->amount_refunded_minor)->toBe(0)
        ->and(Checkout::freshLink($link)->refund_status->value)->toBe('none');

    // ...but the money is already reserved.
    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '600.00'], 'k-2')->assertStatus(422)->assertJsonPath('error.code', 'refund_exceeds_available');
    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '500.00'], 'k-3')->assertCreated();
});

it('refunds in the charged currency of a converted payment', function (): void {
    [$link, , $attempt, $key] = Refunds::scenario();

    // Written directly: the payment was charged in MXN after a conversion.
    Checkout::inTenant($link, static function () use ($attempt): void {
        PaymentAttempt::query()->whereKey($attempt->id)->update(['amount_minor' => 2_460_000, 'currency' => 'MXN']);
    });

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '24600.00'])
        ->assertCreated()
        ->assertJsonPath('currency', 'MXN')
        ->assertJsonPath('amount_minor', 2_460_000);
});

it('refuses to refund a payment that was not captured', function (PaymentAttemptStatus $status): void {
    [$tenant, $link] = Checkout::scenario();
    [, $key] = ApiTestHelpers::key($tenant);
    $attempt = Checkout::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus($status)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => Checkout::connectionOf($link)->id,
    ]));

    Refunds::post($key, ['payment' => $attempt->prefixedId()])->assertStatus(409)->assertJsonPath('error.code', 'payment_not_refundable');
    expect(Refunds::of($link))->toBe([]);
})->with([
    'authorized, not captured' => [PaymentAttemptStatus::RequiresCapture],
    'processing' => [PaymentAttemptStatus::Processing],
    'canceled' => [PaymentAttemptStatus::Canceled],
    'failed' => [PaymentAttemptStatus::Failed],
]);

it('answers 404 for an unknown payment or one of another tenant, and 400 for an ID that is not a payment ID', function (): void {
    [, , $attempt, $key] = Refunds::scenario();
    [$otherTenant] = Checkout::scenario();
    [, $otherKey] = ApiTestHelpers::key($otherTenant);

    Refunds::post($otherKey, ['payment' => $attempt->prefixedId()])->assertNotFound()->assertJsonPath('error.code', 'resource_not_found');
    Refunds::post($key, ['payment' => 'pay_01J8Z4AAAAAAAAAAAAAAAAAAAA'], 'k-2')->assertNotFound();
    Refunds::post($key, ['payment' => 'plink_'.substr($attempt->prefixedId(), 4)], 'k-3')->assertStatus(400)->assertJsonPath('error.param', 'payment');
});

it('validates the body', function (array $body, string $code, ?string $param): void {
    [, , $attempt, $key] = Refunds::scenario();
    $sent = [];

    foreach ($body as $field => $value) {
        $sent[(string) $field] = $value === '{payment}' ? $attempt->prefixedId() : $value;
    }

    $body = $sent;

    $response = Refunds::post($key, $body)->assertStatus(400)->assertJsonPath('error.code', $code);

    if ($param !== null) {
        $response->assertJsonPath('error.param', $param);
    }
})->with([
    'payment missing' => [['amount' => '1.00'], 'parameter_missing', 'payment'],
    'amount as a number' => [['payment' => '{payment}', 'amount' => 10], 'amount_must_be_string', 'amount'],
    'amount with too many decimals' => [['payment' => '{payment}', 'amount' => '1.001'], 'amount_invalid', 'amount'],
    'amount zero' => [['payment' => '{payment}', 'amount' => '0.00'], 'amount_invalid', 'amount'],
    'unknown reason' => [['payment' => '{payment}', 'reason' => 'because'], 'parameter_invalid', 'reason'],
    'unknown parameter' => [['payment' => '{payment}', 'note' => 'x'], 'parameter_invalid', 'note'],
    'payment not an id' => [['payment' => 'abc'], 'parameter_invalid', 'payment'],
]);

it('requires an Idempotency-Key', function (): void {
    [, , $attempt, $key] = Refunds::scenario();

    Refunds::post($key, ['payment' => $attempt->prefixedId()], null)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');
});

it('replays the same answer for the same key and body, and refunds once', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $body = ['payment' => $attempt->prefixedId(), 'amount' => '200.00'];

    $first = Refunds::post($key, $body)->assertCreated();
    $second = Refunds::post($key, $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(Refunds::of($link))->toHaveCount(1)
        ->and($fake->callsTo('refund'))->toHaveCount(1);
});

it('refuses the same key with another body', function (): void {
    [, , $attempt, $key] = Refunds::scenario();

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '200.00'])->assertCreated();
    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '300.00'])->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
});

it('needs refunds:create to create and refunds:read to read', function (): void {
    [$tenant, $link, $fake] = Checkout::scenario();
    Checkout::pay($link)->assertOk();
    $attempt = Checkout::attempts($link)[0];
    [, $readOnly] = ApiTestHelpers::key($tenant, scopes: [ApiScope::RefundsRead, ApiScope::PaymentsRead]);
    [, $writeOnly] = ApiTestHelpers::key($tenant, scopes: [ApiScope::RefundsCreate]);

    Refunds::post($readOnly, ['payment' => $attempt->prefixedId()])->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
    $created = Refunds::post($writeOnly, ['payment' => $attempt->prefixedId(), 'amount' => '10.00'], 'k-2')->assertCreated();
    Refunds::get($writeOnly, Refunds::idOf($created))->assertForbidden();
    Refunds::list($writeOnly)->assertForbidden();
    Refunds::get($readOnly, Refunds::idOf($created))->assertOk()->assertJsonPath('amount', '10.00');
    expect($fake->callsTo('refund'))->toHaveCount(1);
});

it('lists refunds newest first, filtered by payment and status, and pages them', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();

    $a = Refunds::idOf(Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '100.00'], 'k-1')->assertCreated());
    $fake->refundsAs(ProviderRefundStatus::Pending);
    $b = Refunds::idOf(Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '100.00'], 'k-2')->assertCreated());
    $c = Refunds::idOf(Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '100.00'], 'k-3')->assertCreated());

    expect(ApiTestHelpers::listed(Refunds::list($key)))->toBe([$c, $b, $a])
        ->and(ApiTestHelpers::listed(Refunds::list($key, 'status=pending')))->toBe([$c, $b])
        ->and(ApiTestHelpers::listed(Refunds::list($key, 'payment='.$attempt->prefixedId())))->toBe([$c, $b, $a])
        ->and(ApiTestHelpers::listed(Refunds::list($key, 'limit=2')))->toBe([$c, $b])
        ->and(Refunds::list($key, 'limit=2')->json('has_more'))->toBeTrue()
        ->and(ApiTestHelpers::listed(Refunds::list($key, 'limit=2&starting_after='.$b)))->toBe([$a])
        ->and(ApiTestHelpers::listed(Refunds::list($key, 'payment=pay_01J8Z4AAAAAAAAAAAAAAAAAAAA')))->toBe([]);

    Refunds::list($key, 'status=bogus')->assertStatus(400)->assertJsonPath('error.code', 'parameter_invalid');
    Refunds::list($key, 'limit=0')->assertStatus(400);
    Refunds::get($key, $a)->assertOk()->assertJsonPath('id', $a)->assertJsonPath('status', 'succeeded');
    Refunds::get($key, 're_01J8Z4AAAAAAAAAAAAAAAAAAAA')->assertNotFound();
});

it('shows the refunded amount and the refund and dispute summaries on the payment', function (): void {
    [, , $attempt, $key] = Refunds::scenario();

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '500.00'])->assertCreated();

    withHeaders(ApiTestHelpers::headers($key))->getJson(apiUrl('v1/payments/'.$attempt->prefixedId()))
        ->assertOk()
        ->assertJsonPath('amount_refunded', '500.00')
        ->assertJsonPath('amount_refunded_minor', 50000)
        ->assertJsonPath('refund_status', 'partial')
        ->assertJsonPath('dispute_status', 'none');
});

it('tells the integrator with refund.created and refund.succeeded, without gateway identifiers', function (): void {
    [$link, , $attempt, $key] = Refunds::scenario();

    $refund = Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '500.00', 'reason' => 'duplicate'])->assertCreated();

    $created = Refunds::webhookBodies($link, WebhookEventType::RefundCreated);
    $succeeded = Refunds::webhookBodies($link, WebhookEventType::RefundSucceeded);
    $providerRefundId = (string) Refunds::of($link)[0]->provider_refund_id;

    expect($created)->toHaveCount(1)
        ->and($succeeded)->toHaveCount(1)
        ->and(data_get($created[0], 'data.object.id'))->toBe($refund->json('id'))
        ->and(data_get($created[0], 'data.object.object'))->toBe('refund')
        ->and(data_get($created[0], 'data.object.payment'))->toBe($attempt->prefixedId())
        ->and(data_get($created[0], 'data.object.amount'))->toBe('500.00')
        ->and(data_get($succeeded[0], 'data.object.status'))->toBe('succeeded')
        // The payment, with the integrator's own reference, travels along.
        ->and(data_get($succeeded[0], 'data.payment.id'))->toBe($attempt->prefixedId())
        ->and(data_get($succeeded[0], 'data.payment.amount_refunded'))->toBe('500.00')
        ->and(data_get($succeeded[0], 'data.payment.refund_status'))->toBe('partial')
        ->and(json_encode([$created, $succeeded]))->not->toContain($providerRefundId)
        ->and(json_encode($refund->json()))->not->toContain($providerRefundId);
});

it('does not announce refund.succeeded while the refund is pending', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->refundsAs(ProviderRefundStatus::Pending);

    Refunds::post($key, ['payment' => $attempt->prefixedId()])->assertCreated()->assertJsonPath('status', 'pending');

    expect(Refunds::webhookBodies($link, WebhookEventType::RefundCreated))->toHaveCount(1)
        ->and(Refunds::webhookBodies($link, WebhookEventType::RefundSucceeded))->toBe([]);
});

it('keeps the refund as failed, frees the balance and announces refund.failed when the gateway refuses it', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->failNext('refund', new GatewayRequestException('Fake: refused.', 'charge_already_refunded', null, 400));

    Refunds::post($key, ['payment' => $attempt->prefixedId()], 'k-1')
        ->assertCreated()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('failure.code', 'gateway_refused');

    expect(Refunds::webhookBodies($link, WebhookEventType::RefundFailed))->toHaveCount(1)
        ->and(Refunds::attempt($link)->amount_refunded_minor)->toBe(0);

    // The failed refund no longer holds the money: it can be tried again.
    Refunds::post($key, ['payment' => $attempt->prefixedId()], 'k-2')->assertCreated()->assertJsonPath('status', 'succeeded');
});

it('answers 502 when the gateway is unreachable and finishes the same refund on a retry with the same key', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->failNext('refund', new GatewayUnavailableException('Fake: unreachable.'));

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '300.00'])->assertStatus(502)->assertJsonPath('error.code', 'gateway_error');
    expect(Refunds::of($link))->toHaveCount(1)->and(Refunds::of($link)[0]->status)->toBe(RefundState::Pending);

    $retry = Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '300.00'])->assertCreated()->assertJsonPath('status', 'succeeded');

    expect(Refunds::of($link))->toHaveCount(1)
        ->and($retry->json('id'))->toBe(Refunds::of($link)[0]->prefixedId())
        ->and($fake->refundCount((string) $attempt->provider_payment_id))->toBe(1)
        ->and(Refunds::attempt($link)->amount_refunded_minor)->toBe(30000);
});

it('does not refund twice when the gateway did the work but its answer was lost', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->loseNextResponse('refund');

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '300.00'])->assertStatus(502);
    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '300.00'])->assertCreated()->assertJsonPath('status', 'succeeded');

    expect(Refunds::of($link))->toHaveCount(1)
        ->and($fake->refundCount((string) $attempt->provider_payment_id))->toBe(1)
        ->and(Refunds::of($link)[0]->provider_refund_id)->not->toBeNull()
        ->and(Refunds::attempt($link)->amount_refunded_minor)->toBe(30000);
});

it('repeats a refund under a derived key after a stored server error, once', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();
    $fake->serverErrorOnNext('refund');

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '300.00'])->assertCreated()->assertJsonPath('status', 'succeeded');

    expect($fake->refundCount((string) $attempt->provider_payment_id))->toBe(1)
        ->and(Refunds::of($link))->toHaveCount(1);
});

it('audits who requested the refund', function (): void {
    [$link, , $attempt, $key] = Refunds::scenario();

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '500.00', 'reason' => 'fraudulent'])->assertCreated();

    $entries = AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $link->tenant_id)->where('action', 'refund.requested')->get();
    $entry = $entries->first();

    expect($entries)->toHaveCount(1)
        ->and($entry?->actor_type->value)->toBe('api_key')
        ->and($entry?->changes['amount'] ?? null)->toBe('500.00')
        ->and($entry?->changes['reason'] ?? null)->toBe('fraudulent');
});

it('asks the gateway to refund the payment it holds, never a gateway identifier from the caller', function (): void {
    [$link, $fake, $attempt, $key] = Refunds::scenario();

    Refunds::post($key, ['payment' => $attempt->prefixedId(), 'amount' => '100.00'])->assertCreated();

    expect($fake->callsTo('refund'))->toBe(['refund:'.$attempt->provider_payment_id]);
});
