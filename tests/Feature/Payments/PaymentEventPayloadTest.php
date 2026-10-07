<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\VoidAuthorization;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Shared\Time\IsoDateTime;
use App\Modules\Webhooks\Models\WebhookEvent;
use Database\Factories\PaymentLinkFactory;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\WebhookTestHelpers;

/**
 * CRX-4 (spec B2, C4): every `payment.*` event, and the payment inside
 * `payment_link.paid`, carries the link's `client_reference_id` (so the
 * integrator can match it without a lookup), `captured_at` (when the payment
 * was captured; null until then) and the `fx` block (null until the tenant FX
 * is applied, CRX-6/7). Still our identifiers only: no gateway ID, no card
 * number, no fingerprint (ADR-019, ADR-0051, ADR-0058).
 */

/**
 * @return array<string, array<mixed>> decoded webhook bodies by event type (last one wins)
 */
function eventBodies(PaymentLink $link): array
{
    $events = WebhookTestHelpers::in($link->tenant_id, $link->livemode, static fn (): array => WebhookEvent::query()->orderBy('id')->get()->all());
    $bodies = [];

    foreach ($events as $event) {
        $bodies[$event->type->value] = jsonArray($event->payload);
    }

    return $bodies;
}

/**
 * @return Closure(PaymentLinkFactory): PaymentLinkFactory
 */
function linkWithReference(?string $reference = 'ORDER-1029'): Closure
{
    return static fn (PaymentLinkFactory $factory): PaymentLinkFactory => $factory->state(['client_reference_id' => $reference]);
}

it('carries client_reference_id, captured_at and fx in payment.succeeded and in the payment of payment_link.paid', function (): void {
    [, $link] = Checkout::scenario(linkWithReference());

    Checkout::pay($link)->assertOk();

    $attempt = Checkout::attempts($link)[0];
    $bodies = eventBodies($link);
    $capturedAt = IsoDateTime::format($attempt->succeeded_at ?? throw new LogicException('The attempt did not succeed.'));

    foreach ([['payment.succeeded', 'data.object'], ['payment_link.paid', 'data.payment']] as [$type, $path]) {
        expect(data_get($bodies[$type], $path.'.client_reference_id'))->toBe('ORDER-1029')
            ->and(data_get($bodies[$type], $path.'.captured_at'))->toBe($capturedAt)
            ->and(data_get($bodies[$type], $path))->toHaveKey('fx')
            ->and(data_get($bodies[$type], $path.'.fx'))->toBeNull()
            ->and(data_get($bodies[$type], $path.'.id'))->toBe($attempt->prefixedId());
    }
});

it('carries client_reference_id and a null captured_at in payment.processing and payment.failed', function (): void {
    [, $link] = Checkout::scenario(linkWithReference());
    config(['axispay.checkout.turnstile_after_failures' => 99]);

    Checkout::pay($link, 'ctoken_decline');
    $afterDecline = eventBodies($link);

    expect(data_get($afterDecline['payment.failed'], 'data.object.client_reference_id'))->toBe('ORDER-1029')
        ->and(data_get($afterDecline['payment.failed'], 'data.object.captured_at'))->toBeNull()
        ->and(data_get($afterDecline['payment.failed'], 'data.object'))->toHaveKey('fx');

    [, $processingLink] = Checkout::scenario(linkWithReference('ORDER-2'));
    Checkout::pay($processingLink, 'ctoken_processing');

    $processing = eventBodies($processingLink)['payment.processing'];

    expect(data_get($processing, 'data.object.client_reference_id'))->toBe('ORDER-2')
        ->and(data_get($processing, 'data.object.captured_at'))->toBeNull();
});

it('carries client_reference_id and a null captured_at in payment.canceled', function (): void {
    [, $link, $fake] = Checkout::scenario(linkWithReference());
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture);
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);

    Checkout::inTenant($link, static fn () => app(VoidAuthorization::class)->handle($attempt->id, VoidReason::MerchantRejected));

    $canceled = eventBodies($link)['payment.canceled'];

    expect(data_get($canceled, 'data.object.client_reference_id'))->toBe('ORDER-1029')
        ->and(data_get($canceled, 'data.object.captured_at'))->toBeNull()
        ->and(data_get($canceled, 'data.object'))->toHaveKey('fx');
});

it('sends a null client_reference_id when the link has none', function (): void {
    [, $link] = Checkout::scenario(linkWithReference(null));

    Checkout::pay($link)->assertOk();

    $succeeded = eventBodies($link)['payment.succeeded'];

    expect(data_get($succeeded, 'data.object'))->toHaveKey('client_reference_id')
        ->and(data_get($succeeded, 'data.object.client_reference_id'))->toBeNull();
});

it('never puts gateway identifiers or card data in the events', function (): void {
    [, $link] = Checkout::scenario(linkWithReference());

    Checkout::pay($link)->assertOk();

    [$attempt] = Checkout::attempts($link);
    $raw = json_encode(eventBodies($link), JSON_THROW_ON_ERROR);

    expect($raw)->not->toContain((string) $attempt->provider_payment_id)
        ->and($raw)->not->toContain('4242')
        ->and($raw)->not->toContain('fp_fake')
        ->and($raw)->not->toContain('card_last4')
        ->and($raw)->not->toContain('card_brand');
});
