<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Actions\VoidAuthorization;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Models\DomainEvent;
use App\Modules\Webhooks\Models\WebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\ValidationTestHelpers as Validation;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\travel;

/**
 * CRX-3 (spec B2): `payment.canceled` is recorded for EVERY void of an
 * authorization (and of a 3D Secure step), with the void reason and the
 * `pay_` ID of the attempt, in the same transaction that applies the
 * cancellation. Until now no event told the integrator that an authorization
 * it may have approved (pre-payment validation) was released.
 */

/**
 * The public `payment.canceled` webhook bodies of the link's tenant, decoded.
 *
 * @return list<array<mixed>>
 */
function canceledBodies(PaymentLink $link): array
{
    $bodies = [];

    $events = WebhookTestHelpers::in($link->tenant_id, $link->livemode, static fn (): array => WebhookEvent::query()->orderBy('id')->get()->all());

    foreach ($events as $event) {
        if ($event->type === WebhookEventType::PaymentCanceled) {
            $bodies[] = jsonArray($event->payload);
        }
    }

    return $bodies;
}

/**
 * @return list<string> types of every domain event recorded for the link's tenant
 */
function domainEventTypes(PaymentLink $link): array
{
    return Checkout::inTenant($link, static fn (): array => array_values(DomainEvent::query()->orderBy('id')->get()->map(static fn (DomainEvent $event): string => $event->type->value)->all()));
}

it('records payment.canceled with the reason and the pay_ id for each void reason', function (VoidReason $reason, string $token): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture); // authorized, never captured
    Checkout::pay($link, $token);
    [$attempt] = Checkout::attempts($link);
    expect($attempt->status->isInFlight())->toBeTrue();

    Checkout::inTenant($link, static fn () => app(VoidAuthorization::class)->handle($attempt->id, $reason));

    $bodies = canceledBodies($link);
    $fresh = Checkout::attempts($link)[0];

    expect($bodies)->toHaveCount(1)
        ->and($bodies[0]['type'])->toBe('payment.canceled')
        ->and(data_get($bodies[0], 'data.object.id'))->toBe($attempt->prefixedId())
        ->and(data_get($bodies[0], 'data.object.object'))->toBe('payment')
        ->and(data_get($bodies[0], 'data.object.status'))->toBe(PaymentAttemptStatus::Canceled->value)
        ->and(data_get($bodies[0], 'data.object.payment_link'))->toBe($link->prefixedId())
        ->and(data_get($bodies[0], 'data.reason'))->toBe($reason->value)
        ->and($fresh->status)->toBe(PaymentAttemptStatus::Canceled)
        // No gateway identifier ever leaves (ADR-019).
        ->and(json_encode($bodies[0]))->not->toContain((string) $attempt->provider_payment_id);
})->with([
    'merchant rejected' => [VoidReason::MerchantRejected, 'ctoken_success'],
    'validation failed (fail_closed)' => [VoidReason::ValidationFailed, 'ctoken_success'],
    'capture window elapsed' => [VoidReason::CaptureWindowElapsed, 'ctoken_success'],
    'link closed' => [VoidReason::LinkClosed, 'ctoken_success'],
    'abandoned 3D Secure step' => [VoidReason::AbandonedAction, 'ctoken_threeds'],
]);

it('sends payment.canceled when the merchant rejects the validation', function (): void {
    Notification::fake();
    Http::fake(['*' => Validation::answer(['decision' => 'reject', 'reason_code' => 'out_of_stock'])]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    $bodies = canceledBodies($link);
    expect($bodies)->toHaveCount(1)
        ->and(data_get($bodies[0], 'data.reason'))->toBe('merchant_rejected')
        ->and(data_get($bodies[0], 'data.object.id'))->toBe(Checkout::attempts($link)[0]->prefixedId());
});

it('sends payment.canceled when the validation fails under fail_closed', function (): void {
    Notification::fake();
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out')]);
    [, $link] = Validation::scenario();

    Checkout::pay($link)->assertJson(['outcome' => 'merchant_rejected']);

    $bodies = canceledBodies($link);
    expect($bodies)->toHaveCount(1)
        ->and(data_get($bodies[0], 'data.reason'))->toBe('validation_failed');
});

it('sends payment.canceled when the reconciliation voids an authorization past the capture window', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture);
    Checkout::pay($link);

    travel(20)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    $bodies = canceledBodies($link);
    expect($bodies)->toHaveCount(1)
        ->and(data_get($bodies[0], 'data.reason'))->toBe('capture_window_elapsed');
});

it('sends payment.canceled when the reconciliation cancels an abandoned 3D Secure step', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_threeds');

    travel(15)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();
    expect(canceledBodies($link))->toBe([]);

    travel(20)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    $bodies = canceledBodies($link);
    expect($bodies)->toHaveCount(1)
        ->and(data_get($bodies[0], 'data.reason'))->toBe('abandoned_action');
});

it('sends payment.canceled when a refused capture shows the gateway released the authorization', function (): void {
    [, $link, $fake] = Checkout::scenario();
    // The capture never reaches the gateway at first: the authorization stays.
    $fake->failNext('capturePayment', new GatewayUnavailableException('Fake: timeout.'));
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);
    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    // The authorization then expires at the gateway: the next capture is
    // refused and the payment read after it is already canceled.
    $fake->strictStates();
    $fake->beforeNext('capturePayment', static fn () => $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Canceled));

    Checkout::inTenant($link, static fn () => app(CaptureAuthorizedPayment::class)->handle($attempt->id));

    $bodies = canceledBodies($link);
    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($bodies)->toHaveCount(1)
        ->and(data_get($bodies[0], 'data.reason'))->toBe(VoidReason::GatewayCanceled->value);
});

it('records the event once when a late webhook reports the same cancellation again', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture);
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);

    Checkout::inTenant($link, static fn () => app(VoidAuthorization::class)->handle($attempt->id, VoidReason::LinkClosed));
    Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Webhook));

    expect(canceledBodies($link))->toHaveCount(1)
        ->and(array_count_values(domainEventTypes($link))['payment.canceled'])->toBe(1);
});

it('sends nothing for an attempt that never reached the gateway', function (): void {
    [, $link] = Checkout::scenario();
    $attemptId = Checkout::inTenant($link, static fn (): string => PaymentAttempt::factory()->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => Checkout::connectionOf($link)->id,
        'provider_payment_id' => null,
    ])->id);

    Checkout::inTenant($link, static fn () => app(VoidAuthorization::class)->handle($attemptId, VoidReason::LinkClosed));

    expect(canceledBodies($link))->toBe([]);
});
