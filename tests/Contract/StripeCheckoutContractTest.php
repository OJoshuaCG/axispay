<?php

declare(strict_types=1);

use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use App\Modules\Gateways\Stripe\StripeClientFactory;
use App\Modules\Gateways\Stripe\StripeGateway;
use App\Modules\Shared\Ids\Ulid;

/**
 * Phase 4 ACCEPTANCE GATE (ADR-0051): the payment methods of StripeGateway
 * against REAL Stripe test mode. Skipped unless the keys are exported in the
 * shell (never read from .env):
 *
 *   STRIPE_TEST_SECRET=sk_test_...            platform key (Connect)
 *   STRIPE_CONTRACT_CONNECTED_ACCOUNT=acct_...  an MX connected account that can charge
 *   STRIPE_CONTRACT_RESTRICTED_KEY=rk_test_...  merchant key (api_key method)
 *   STRIPE_CONTRACT_PUBLISHABLE_KEY=pk_test_...
 *
 *   ./vendor/bin/pest --group=stripe
 *
 * The ConfirmationToken is created with Stripe's test helper
 * (POST /v1/test_helpers/confirmation_tokens) from test payment methods, so
 * no browser is needed. This file is list A of the gate (automated): items
 * 1, 2, 3, 4 (the resulting state), 8 (server half), 9 and 10. Run the
 * api_key variant with a restricted key that holds ONLY the documented
 * permissions (item 10). List B of the gate in ADR-0051 is checked by hand
 * and recorded there (the cost and statement of a void, the authorization
 * lifetime, elements.update(), fonts in the card iframe, the browser half
 * of 3D Secure, billing details, Connect event delivery).
 */
function checkoutContractEnv(string $name): ?string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : null;
}

function checkoutContractReady(string ...$names): bool
{
    foreach ($names as $name) {
        $value = (string) checkoutContractEnv($name);

        if (! str_contains($value, '_test_') && ! str_starts_with($value, 'acct_')) {
            return false;
        }
    }

    return true;
}

const CHECKOUT_CONTRACT_SKIP = 'Export the keys listed in tests/Contract/StripeCheckoutContractTest.php to run the Phase 4 Stripe acceptance gate.';

function contractConnection(ConnectionMethod $method): GatewayConnection
{
    $connection = (new GatewayConnection)->forceFill([
        'id' => Ulid::generate(),
        'tenant_id' => Ulid::generate(),
        'livemode' => false,
        'connection_method' => $method,
    ]);

    if ($method === ConnectionMethod::ApiKey) {
        $encrypted = app(GatewayCredentialsEncrypter::class)->encrypt((string) checkoutContractEnv('STRIPE_CONTRACT_RESTRICTED_KEY'));
        $connection->forceFill([
            'credentials_secret' => $encrypted->ciphertext,
            'credentials_key_version' => $encrypted->keyVersion,
            'credentials_publishable' => checkoutContractEnv('STRIPE_CONTRACT_PUBLISHABLE_KEY'),
            'provider_account_id' => 'acct_contract',
        ]);
    } else {
        $connection->forceFill(['provider_account_id' => checkoutContractEnv('STRIPE_CONTRACT_CONNECTED_ACCOUNT')]);
    }

    return $connection;
}

/** A ConfirmationToken on the connection's account from a Stripe test payment method. */
function contractConfirmationToken(GatewayConnection $connection, string $testPaymentMethod): string
{
    $context = app(StripeClientFactory::class)->for($connection);

    return $context->client->testHelpers->confirmationTokens->create(['payment_method' => $testPaymentMethod], $context->options())->id;
}

function contractPayment(GatewayConnection $connection): string
{
    return app(StripeGateway::class)->createOrUpdatePayment($connection, new PaymentRequest(
        amountMinor: 15_000,
        currency: 'MXN',
        description: 'AxisPay contract test',
        metadata: ['axispay_attempt_id' => Ulid::generate()],
        idempotencyKey: 'axispay:contract:'.Ulid::generate(),
    ))->providerPaymentId;
}

beforeEach(function (): void {
    config(['services.stripe.test.secret' => checkoutContractEnv('STRIPE_TEST_SECRET')]);
});

/*
 * Each test runs once per connection method: a direct charge on the
 * connected account (platform key + Stripe-Account) and the api_key method
 * (the merchant's restricted key, no Stripe-Account).
 */
foreach ([
    'direct charge on the connected account' => [ConnectionMethod::PlatformOnboarding, ['STRIPE_TEST_SECRET', 'STRIPE_CONTRACT_CONNECTED_ACCOUNT']],
    'api_key (merchant restricted key)' => [ConnectionMethod::ApiKey, ['STRIPE_CONTRACT_RESTRICTED_KEY', 'STRIPE_CONTRACT_PUBLISHABLE_KEY']],
] as $label => [$method, $keys]) {
    it('reads the card country behind a ConfirmationToken (gate 2) — '.$label, function () use ($method): void {
        $connection = contractConnection($method);
        $card = app(StripeGateway::class)->inspectPaymentMethod($connection, contractConfirmationToken($connection, 'pm_card_mx'));

        expect($card->country)->toBe('MX')->and($card->brand)->not->toBeNull()->and($card->last4)->not->toBeNull();
    })->group('stripe')->skip(! checkoutContractReady(...$keys), CHECKOUT_CONTRACT_SKIP);

    it('authorizes with manual capture, then captures (gates 1 and 3) — '.$label, function () use ($method): void {
        $connection = contractConnection($method);
        $gateway = app(StripeGateway::class);
        $id = contractPayment($connection);

        $authorized = $gateway->confirmPayment($connection, $id, contractConfirmationToken($connection, 'pm_card_mx'), 'axispay:contract:'.Ulid::generate(), 'https://example.com/complete');
        expect($authorized->status)->toBe(ProviderPaymentStatus::RequiresCapture)
            ->and($authorized->amountCapturableMinor)->toBe(15_000)
            // Gate 5: the authorization's expiry, to compare with the documented 7 days.
            ->and($authorized->captureBefore)->not->toBeNull();

        $captured = $gateway->capturePayment($connection, $id, 'axispay:contract:'.Ulid::generate());
        expect($captured->status)->toBe(ProviderPaymentStatus::Succeeded);
    })->group('stripe')->skip(! checkoutContractReady(...$keys), CHECKOUT_CONTRACT_SKIP);

    it('voids an uncaptured authorization (gate 4) — '.$label, function () use ($method): void {
        $connection = contractConnection($method);
        $gateway = app(StripeGateway::class);
        $id = contractPayment($connection);
        $gateway->confirmPayment($connection, $id, contractConfirmationToken($connection, 'pm_card_visa'), 'axispay:contract:'.Ulid::generate(), 'https://example.com/complete');

        expect($gateway->cancelPayment($connection, $id, 'axispay:contract:'.Ulid::generate())->status)->toBe(ProviderPaymentStatus::Canceled);
    })->group('stripe')->skip(! checkoutContractReady(...$keys), CHECKOUT_CONTRACT_SKIP);

    it('returns a decline as the payment with a referenced failure (gate 9) — '.$label, function () use ($method): void {
        $connection = contractConnection($method);
        $payment = app(StripeGateway::class)->confirmPayment($connection, contractPayment($connection), contractConfirmationToken($connection, 'pm_card_chargeDeclined'), 'axispay:contract:'.Ulid::generate(), 'https://example.com/complete');

        expect($payment->status)->toBe(ProviderPaymentStatus::RequiresPaymentMethod)
            ->and($payment->failure?->reference)->toStartWith('ch_')
            ->and($payment->failure?->code)->toBe('card_declined');
    })->group('stripe')->skip(! checkoutContractReady(...$keys), CHECKOUT_CONTRACT_SKIP);

    it('asks for 3D Secure with a client secret for Stripe.js (gate 8, server half) — '.$label, function () use ($method): void {
        $connection = contractConnection($method);
        $payment = app(StripeGateway::class)->confirmPayment($connection, contractPayment($connection), contractConfirmationToken($connection, 'pm_card_threeDSecure2Required'), 'axispay:contract:'.Ulid::generate(), 'https://example.com/complete');

        expect($payment->status)->toBe(ProviderPaymentStatus::RequiresAction)
            ->and($payment->clientSecret)->toContain('_secret_');
    })->group('stripe')->skip(! checkoutContractReady(...$keys), CHECKOUT_CONTRACT_SKIP);
}
