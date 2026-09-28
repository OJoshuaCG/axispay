<?php

declare(strict_types=1);

use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\StripeGateway;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Database\Factories\GatewayConnectionFactory;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeFixtures;

/**
 * StripeGateway payment methods (Phase 4, ADR-0051) against Stripe's HTTP
 * layer faked: the parameters of plan 12.4 and ADR-0050 (card only, manual
 * capture, our IDs only in metadata), the Stripe-Account header for Connect
 * methods and none for api_key (plan 12.4.1), idempotency keys (rule 5) and
 * the mapping to the provider-neutral payment.
 */
function stripePayments(): StripeGateway
{
    return app(StripeGateway::class);
}

/**
 * @param  array<mixed>  $changes
 * @return array<mixed>
 */
function intentFixture(string $id, string $status, array $changes = []): array
{
    return array_replace_recursive(StripeFixtures::load('payment_intent', ['id' => $id, 'status' => $status, 'attempt' => '01K6AAAAAAAAAAAAAAAAAAAAAA', 'link' => '01K6BBBBBBBBBBBBBBBBBBBBBB']), $changes);
}

function paymentsConnection(bool $apiKey = false): GatewayConnection
{
    $tenant = Tenant::factory()->create();

    return GatewayTestHelpers::connection($tenant, false, $apiKey
        ? static fn (GatewayConnectionFactory $f): GatewayConnectionFactory => $f->apiKey()
        : static fn (GatewayConnectionFactory $f): GatewayConnectionFactory => $f->state(['provider_account_id' => 'acct_Payments01']));
}

it('creates a card-only, manual-capture direct charge on the connected account', function (): void {
    $connection = paymentsConnection();
    stripeHttp()->on('post', '/v1/payment_intents', intentFixture('pi_New0001', 'requires_payment_method'));

    $payment = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripePayments()->createOrUpdatePayment($connection, new PaymentRequest(150_000, 'USD', 'Order #A-1029', ['axispay_attempt_id' => 'att'], 'axispay:create_pi:att')));
    $request = stripeHttp()->requestsTo('post', '/v1/payment_intents')[0];

    expect($request['headers']['stripe-account'])->toBe('acct_Payments01')
        ->and($request['headers']['idempotency-key'])->toBe('axispay:create_pi:att')
        ->and($request['params'])->toMatchArray(['amount' => 150000, 'currency' => 'usd', 'capture_method' => 'manual', 'allowed_payment_method_types' => ['card'], 'metadata' => ['axispay_attempt_id' => 'att']])
        ->and($request['params'])->not->toHaveKey('application_fee_amount')
        ->and($payment->status)->toBe(ProviderPaymentStatus::RequiresPaymentMethod)
        ->and($payment->currency)->toBe('USD')
        ->and($payment->attemptReference)->toBe('01K6AAAAAAAAAAAAAAAAAAAAAA');
});

it('confirms with the confirmation token for Stripe.js next actions, without Stripe-Account for api_key', function (): void {
    $connection = paymentsConnection(apiKey: true);
    stripeHttp()->on('post', '/v1/payment_intents/pi_Conf0001/confirm', intentFixture('pi_Conf0001', 'requires_action'));

    $payment = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripePayments()->confirmPayment($connection, 'pi_Conf0001', 'ctoken_1Abc', 'axispay:confirm:att:ctoken_1Abc', 'https://pay.localhost/l/tok/complete'));
    $request = stripeHttp()->requestsTo('post', '/v1/payment_intents/pi_Conf0001/confirm')[0];

    expect($request['headers'])->not->toHaveKey('stripe-account')
        ->and($request['headers']['authorization'])->toContain('rk_test_')
        ->and($request['params'])->toMatchArray(['confirmation_token' => 'ctoken_1Abc', 'use_stripe_sdk' => 'true', 'return_url' => 'https://pay.localhost/l/tok/complete'])
        ->and($payment->status)->toBe(ProviderPaymentStatus::RequiresAction)
        ->and($payment->clientSecret)->toBe('pi_Conf0001_secret_FakeSecret')
        ->and($payment->cardPreview?->brand)->toBe('visa')
        ->and($payment->cardPreview?->last4)->toBe('4242')
        ->and($payment->captureBefore)->not->toBeNull();
});

it('turns a card decline (402) into the payment with its failure', function (): void {
    $connection = paymentsConnection();
    stripeHttp()->error('post', '/v1/payment_intents/pi_Decl0001/confirm', 402, 'card_error', 'card_declined');
    stripeHttp()->on('get', '/v1/payment_intents/pi_Decl0001', intentFixture('pi_Decl0001', 'requires_payment_method', [
        'last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.', 'charge' => 'ch_Declined01'],
    ]));

    $payment = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripePayments()->confirmPayment($connection, 'pi_Decl0001', 'ctoken_2', 'k', 'https://pay.localhost/x'));

    expect($payment->status)->toBe(ProviderPaymentStatus::RequiresPaymentMethod)
        ->and($payment->failure?->reference)->toBe('ch_Declined01')
        ->and($payment->failure?->declineCode)->toBe('insufficient_funds')
        ->and($payment->clientSecret)->toBeNull();
});

it('captures and voids with idempotency keys', function (): void {
    $connection = paymentsConnection();
    stripeHttp()->on('post', '/v1/payment_intents/pi_Cap0001/capture', intentFixture('pi_Cap0001', 'succeeded'));
    stripeHttp()->on('post', '/v1/payment_intents/pi_Void0001/cancel', intentFixture('pi_Void0001', 'canceled'));

    [$captured, $voided] = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => [
        stripePayments()->capturePayment($connection, 'pi_Cap0001', 'axispay:capture:att'),
        stripePayments()->cancelPayment($connection, 'pi_Void0001', 'axispay:cancel:att'),
    ]);

    expect($captured->status)->toBe(ProviderPaymentStatus::Succeeded)
        ->and($voided->status)->toBe(ProviderPaymentStatus::Canceled)
        ->and(stripeHttp()->requestsTo('post', '/v1/payment_intents/pi_Cap0001/capture')[0]['headers']['idempotency-key'])->toBe('axispay:capture:att')
        ->and(stripeHttp()->requestsTo('post', '/v1/payment_intents/pi_Void0001/cancel')[0]['headers']['idempotency-key'])->toBe('axispay:cancel:att');
});

it('reads the card of a confirmation token', function (): void {
    $connection = paymentsConnection();
    stripeHttp()->on('get', '/v1/confirmation_tokens/ctoken_Card01', ['id' => 'ctoken_Card01', 'object' => 'confirmation_token', 'payment_method_preview' => ['type' => 'card', 'card' => ['brand' => 'mastercard', 'country' => 'US', 'last4' => '4444']]]);

    $card = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripePayments()->inspectPaymentMethod($connection, 'ctoken_Card01'));

    expect($card->country)->toBe('US')->and($card->brand)->toBe('mastercard')->and($card->last4)->toBe('4444')
        ->and(stripeHttp()->requestsTo('get', '/v1/confirmation_tokens/ctoken_Card01')[0]['headers']['stripe-account'])->toBe('acct_Payments01');
});

it('maps every PaymentIntent status and each payment event to one kind', function (): void {
    foreach (['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.processing', 'payment_intent.canceled', 'payment_intent.requires_action', 'payment_intent.amount_capturable_updated'] as $type) {
        expect(stripePayments()->eventKind($type, false)->value)->toBe('payment_updated')
            ->and(stripePayments()->eventKind($type, true)->value)->toBe('payment_updated');
    }

    expect(stripePayments()->eventKind('payment_intent.created', false)->value)->toBe('unhandled');
});

it('returns the current payment when a retried confirmation finds it already confirmed', function (): void {
    $connection = paymentsConnection();
    stripeHttp()->error('post', '/v1/payment_intents/pi_Again0001/confirm', 400, 'invalid_request_error', 'payment_intent_unexpected_state');
    stripeHttp()->on('get', '/v1/payment_intents/pi_Again0001', intentFixture('pi_Again0001', 'requires_capture', ['amount_capturable' => 150000]));

    $payment = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripePayments()->confirmPayment($connection, 'pi_Again0001', 'ctoken_again', 'axispay:confirm:x:ctoken_again', 'https://pay.localhost/x'));

    expect($payment->status)->toBe(ProviderPaymentStatus::RequiresCapture);
});

it('returns the current payment when a capture finds it no longer capturable (captured elsewhere or canceled)', function (string $status, ProviderPaymentStatus $expected): void {
    $connection = paymentsConnection();
    stripeHttp()->error('post', '/v1/payment_intents/pi_Late0001/capture', 400, 'invalid_request_error', 'payment_intent_unexpected_state');
    stripeHttp()->on('get', '/v1/payment_intents/pi_Late0001', intentFixture('pi_Late0001', $status));

    $payment = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripePayments()->capturePayment($connection, 'pi_Late0001', 'axispay:capture:att'));

    expect($payment->status)->toBe($expected)
        ->and(stripeHttp()->requestsTo('get', '/v1/payment_intents/pi_Late0001'))->toHaveCount(1);
})->with([
    'captured elsewhere' => ['succeeded', ProviderPaymentStatus::Succeeded],
    'canceled' => ['canceled', ProviderPaymentStatus::Canceled],
]);

it('reads the card fingerprint of a confirmation token for forensics', function (): void {
    $connection = paymentsConnection();
    stripeHttp()->on('get', '/v1/confirmation_tokens/ctoken_Fp01', ['id' => 'ctoken_Fp01', 'object' => 'confirmation_token', 'payment_method_preview' => ['type' => 'card', 'card' => ['brand' => 'visa', 'country' => 'MX', 'last4' => '4242', 'fingerprint' => 'Xt5EWLLDS7FJjR1c']]]);

    $card = app(TenantContext::class)->runAsTenant($connection->tenant_id, false, static fn () => stripePayments()->inspectPaymentMethod($connection, 'ctoken_Fp01'));

    expect($card->fingerprint)->toBe('Xt5EWLLDS7FJjR1c');
});
