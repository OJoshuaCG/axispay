<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Models\WebhookEvent;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\FakePaymentGateway;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\withHeaders;

/**
 * CRX-13 (spec B10): `POST /v1/payments/{id}/void` releases an authorization
 * that was not captured yet (`requires_capture`): no money moves and nothing
 * is refunded. It uses the scope `refunds:create`, the permission of undoing a
 * charge (ADR-0066). It shares the attempt's lease with the capture, so it
 * never races it: whoever holds the lease decides, the other one backs off.
 */

/**
 * A payment authorized but not captured (the gateway holds the money, nobody
 * captured it yet), with an API key.
 *
 * @return array{0: PaymentLink, 1: FakePaymentGateway, 2: PaymentAttempt, 3: string}
 */
function authorizedScenario(): array
{
    [$tenant, $link, $fake] = Checkout::scenario();
    [, $key] = ApiTestHelpers::key($tenant);
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture);
    Checkout::pay($link);
    $attempt = Checkout::attempts($link)[0];
    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    return [$link, $fake, $attempt, $key];
}

/**
 * @return TestResponse<Response>
 */
function voidPayment(string $key, string $id, ?string $idempotencyKey = null): TestResponse
{
    return withHeaders(ApiTestHelpers::headers($key, $idempotencyKey))->postJson(apiUrl('v1/payments/'.$id.'/void'));
}

it('voids an authorized payment and tells the integrator why', function (): void {
    [$link, $fake, $attempt, $key] = authorizedScenario();

    voidPayment($key, $attempt->prefixedId())
        ->assertOk()
        ->assertJsonPath('id', $attempt->prefixedId())
        ->assertJsonPath('object', 'payment')
        ->assertJsonPath('status', 'canceled');

    $events = WebhookTestHelpers::in($link->tenant_id, $link->livemode, static fn (): array => WebhookEvent::query()->orderBy('id')->get()->all());
    $canceled = array_values(array_filter($events, static fn (WebhookEvent $event): bool => $event->type === WebhookEventType::PaymentCanceled));

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('cancelPayment'))->toHaveCount(1)
        ->and($canceled)->toHaveCount(1)
        ->and(data_get(jsonArray($canceled[0]->payload), 'data.reason'))->toBe('merchant_requested')
        // The payer may try again: closing the link is the integrator's business decision.
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

it('answers the same for a payment that is already canceled', function (): void {
    [, $fake, $attempt, $key] = authorizedScenario();

    voidPayment($key, $attempt->prefixedId())->assertOk();
    voidPayment($key, $attempt->prefixedId())->assertOk()->assertJsonPath('status', 'canceled');

    expect($fake->callsTo('cancelPayment'))->toHaveCount(1);
});

it('refuses a payment that is not an authorization waiting for capture', function (PaymentAttemptStatus $status): void {
    [$tenant, $link] = Checkout::scenario();
    [, $key] = ApiTestHelpers::key($tenant);
    $attempt = Checkout::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus($status)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => Checkout::connectionOf($link)->id,
    ]));

    voidPayment($key, $attempt->prefixedId())->assertStatus(409)->assertJsonPath('error.code', 'payment_not_voidable');
})->with([
    'captured: refund it instead' => [PaymentAttemptStatus::Succeeded],
    'processing' => [PaymentAttemptStatus::Processing],
    'waiting for a card' => [PaymentAttemptStatus::RequiresPaymentMethod],
    'failed' => [PaymentAttemptStatus::Failed],
]);

it('backs off with 409 payment_busy while the capture holds the payment, and voids once it lets go', function (): void {
    [$link, $fake, $attempt, $key] = authorizedScenario();
    $token = Checkout::inTenant($link, static fn (): ?string => app(AttemptLease::class)->acquire($attempt->id));
    expect($token)->not->toBeNull();

    voidPayment($key, $attempt->prefixedId())->assertStatus(409)->assertJsonPath('error.code', 'payment_busy');
    expect($fake->callsTo('cancelPayment'))->toBe([])
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    Checkout::inTenant($link, static fn () => app(AttemptLease::class)->release($attempt->id, (string) $token));

    voidPayment($key, $attempt->prefixedId())->assertOk()->assertJsonPath('status', 'canceled');
});

it('does not void a payment the capture won, and tells the integrator to refund it', function (): void {
    [$link, $fake, $attempt, $key] = authorizedScenario();
    $providerPaymentId = (string) $attempt->provider_payment_id;
    // The gateway captured it between our check and our cancel call.
    $fake->beforeNext('cancelPayment', static fn () => $fake->setPaymentStatus($providerPaymentId, ProviderPaymentStatus::Succeeded));

    voidPayment($key, $attempt->prefixedId())->assertStatus(409)->assertJsonPath('error.code', 'payment_not_voidable');

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded);
});

it('answers 502 and leaves the authorization as it was when the gateway is unreachable', function (): void {
    [$link, $fake, $attempt, $key] = authorizedScenario();
    $fake->failNext('cancelPayment', new GatewayUnavailableException('Fake: unreachable.'));

    voidPayment($key, $attempt->prefixedId())->assertStatus(502)->assertJsonPath('error.code', 'gateway_error');

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    // The lease was released: a retry works.
    voidPayment($key, $attempt->prefixedId())->assertOk();
});

it('needs the refunds:create scope', function (): void {
    [$link, , $attempt] = authorizedScenario();
    [, $readOnly] = ApiTestHelpers::key(Tenant::query()->findOrFail($link->tenant_id), scopes: [ApiScope::PaymentsRead, ApiScope::RefundsRead]);

    voidPayment($readOnly, $attempt->prefixedId())->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
});

it('answers 404 for a payment of another tenant or an unknown one', function (): void {
    [, , $attempt] = authorizedScenario();
    [$otherTenant] = Checkout::scenario();
    [, $otherKey] = ApiTestHelpers::key($otherTenant);

    voidPayment($otherKey, $attempt->prefixedId())->assertNotFound()->assertJsonPath('error.code', 'resource_not_found');
    voidPayment($otherKey, 'pay_01J8Z4AAAAAAAAAAAAAAAAAAAA')->assertNotFound();
});
