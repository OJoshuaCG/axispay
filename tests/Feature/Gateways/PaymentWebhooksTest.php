<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Models\DomainEvent;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApiTestHelpers;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeFixtures;

use function Pest\Laravel\call;

/**
 * Plan 14.2-14.4 for payment events (Phase 4) through the real StripeGateway:
 * the handler re-reads the PaymentIntent (ADR-017) and applies its current
 * state; duplicates are processed once (case 3), out-of-order events end in
 * the right state (case 4), a late success wins (case 2), foreign payments
 * are ignored with a reduced payload before any call to Stripe (14.4).
 */
beforeEach(function (): void {
    Notification::fake();
});

/**
 * @return array{0: PaymentLink, 1: PaymentAttempt}
 */
function webhookAttempt(PaymentLinkStatus $linkStatus = PaymentLinkStatus::Processing, PaymentAttemptStatus $status = PaymentAttemptStatus::Processing): array
{
    $tenant = Tenant::factory()->create();
    $connection = GatewayTestHelpers::connection($tenant, false, static fn ($f) => $f->state(['provider_account_id' => 'acct_Hooks0001']));
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->inStatus($linkStatus));

    $attempt = app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus($status)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => $connection->id,
        'provider_account_id' => 'acct_Hooks0001',
        'provider_payment_id' => 'pi_Hook'.substr(bin2hex(random_bytes(6)), 0, 10),
    ]));

    return [$link, $attempt];
}

/**
 * @param  array<string, mixed>  $metadata
 * @return array<string, mixed>
 */
function paymentEvent(string $type, string $eventId, string $intentId, array $metadata): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2026-08-26.dahlia',
        'account' => 'acct_Hooks0001',
        'created' => 1790000300,
        'livemode' => false,
        'type' => $type,
        'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent', 'amount' => 150000, 'currency' => 'usd', 'status' => 'processing', 'metadata' => $metadata, 'receipt_email' => 'payer@example.com']],
    ];
}

/**
 * @param  array<mixed>  $event
 */
function postPaymentEvent(array $event): void
{
    [$body, $signature] = StripeFixtures::signed($event, 'whsec_testconnectsecret');
    call('POST', apiUrl('/webhooks/stripe/connect/test'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
}

/**
 * @param  array<mixed>  $changes
 */
function stripeIntentIs(PaymentAttempt $attempt, string $status, array $changes = []): void
{
    stripeHttp()->on('get', '/v1/payment_intents/'.$attempt->provider_payment_id, array_replace_recursive(
        StripeFixtures::load('payment_intent', ['id' => (string) $attempt->provider_payment_id, 'status' => $status, 'attempt' => $attempt->id, 'link' => $attempt->payment_link_id]),
        $changes,
    ));
}

function freshAttempt(PaymentAttempt $attempt): PaymentAttempt
{
    return app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn () => PaymentAttempt::query()->findOrFail($attempt->id));
}

function freshLinkOf(PaymentAttempt $attempt): PaymentLink
{
    return app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn () => PaymentLink::query()->findOrFail($attempt->payment_link_id));
}

it('re-reads the PaymentIntent and marks the attempt and the link paid, once (case 3)', function (): void {
    [, $attempt] = webhookAttempt();
    stripeIntentIs($attempt, 'succeeded');
    $event = paymentEvent('payment_intent.succeeded', 'evt_PaySucc0001', (string) $attempt->provider_payment_id, ['axispay_attempt_id' => $attempt->id]);

    postPaymentEvent($event);
    postPaymentEvent($event);

    $stored = ProviderEvent::query()->withoutGlobalScopes()->get();

    expect($stored)->toHaveCount(1)
        ->and($stored->first()?->status)->toBe(ProviderEventStatus::Processed)
        ->and($stored->first()?->payment_attempt_id)->toBe($attempt->id)
        ->and(freshAttempt($attempt)->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(freshLinkOf($attempt)->status)->toBe(PaymentLinkStatus::Paid)
        ->and(stripeHttp()->requestsTo('get', '/v1/payment_intents/'.$attempt->provider_payment_id))->toHaveCount(1)
        ->and(app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn () => DomainEvent::query()->where('type', DomainEventType::PaymentSucceeded->value)->count()))->toBe(1);
});

it('ends in the right state when succeeded arrives before processing (case 4)', function (): void {
    [, $attempt] = webhookAttempt(PaymentLinkStatus::Processing, PaymentAttemptStatus::RequiresAction);
    stripeIntentIs($attempt, 'succeeded');

    postPaymentEvent(paymentEvent('payment_intent.succeeded', 'evt_Order0002', (string) $attempt->provider_payment_id, ['axispay_attempt_id' => $attempt->id]));
    postPaymentEvent(paymentEvent('payment_intent.processing', 'evt_Order0001', (string) $attempt->provider_payment_id, ['axispay_attempt_id' => $attempt->id]));

    expect(freshAttempt($attempt)->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(freshLinkOf($attempt)->status)->toBe(PaymentLinkStatus::Paid);
});

it('lets a late success win on an expired link: paid, anomaly audited, late flag kept (case 2)', function (PaymentLinkStatus $closed): void {
    [$link, $attempt] = webhookAttempt($closed, PaymentAttemptStatus::RequiresCapture);
    stripeIntentIs($attempt, 'succeeded');

    postPaymentEvent(paymentEvent('payment_intent.succeeded', 'evt_Late'.$closed->value, (string) $attempt->provider_payment_id, ['axispay_attempt_id' => $attempt->id]));

    $events = app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn () => DomainEvent::query()->where('type', DomainEventType::PaymentSucceeded->value)->get());

    expect(freshLinkOf($attempt)->status)->toBe(PaymentLinkStatus::Paid)
        ->and(freshAttempt($attempt)->late_payment)->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and($events->first()?->data['late_payment'] ?? null)->toBeTrue()
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PaymentLateSucceeded->value)->where('tenant_id', $link->tenant_id)->count())->toBe(1);
})->with([PaymentLinkStatus::Expired, PaymentLinkStatus::Canceled]);

it('captures an authorization reported by webhook when the payer left after 3D Secure', function (): void {
    [, $attempt] = webhookAttempt(PaymentLinkStatus::Processing, PaymentAttemptStatus::RequiresAction);
    stripeIntentIs($attempt, 'requires_capture', ['amount_capturable' => 150000]);
    stripeHttp()->on('post', '/v1/payment_intents/'.$attempt->provider_payment_id.'/capture', StripeFixtures::load('payment_intent', ['id' => (string) $attempt->provider_payment_id, 'status' => 'succeeded', 'attempt' => $attempt->id, 'link' => $attempt->payment_link_id]));

    postPaymentEvent(paymentEvent('payment_intent.amount_capturable_updated', 'evt_Capt0001', (string) $attempt->provider_payment_id, ['axispay_attempt_id' => $attempt->id]));

    expect(stripeHttp()->requestsTo('post', '/v1/payment_intents/'.$attempt->provider_payment_id.'/capture')[0]['headers']['idempotency-key'])->toBe('axispay:capture:'.$attempt->id)
        ->and(freshAttempt($attempt)->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(freshLinkOf($attempt)->status)->toBe(PaymentLinkStatus::Paid);
});

it('records a decline reported by webhook once and frees the link', function (): void {
    [, $attempt] = webhookAttempt(PaymentLinkStatus::Processing, PaymentAttemptStatus::RequiresAction);
    stripeIntentIs($attempt, 'requires_payment_method', ['last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'do_not_honor', 'charge' => 'ch_Hook0001']]);

    postPaymentEvent(paymentEvent('payment_intent.payment_failed', 'evt_Fail0001', (string) $attempt->provider_payment_id, ['axispay_attempt_id' => $attempt->id]));
    postPaymentEvent(paymentEvent('payment_intent.payment_failed', 'evt_Fail0002', (string) $attempt->provider_payment_id, ['axispay_attempt_id' => $attempt->id]));

    $fresh = freshAttempt($attempt);

    expect($fresh->status)->toBe(PaymentAttemptStatus::RequiresPaymentMethod)
        ->and($fresh->failure_count)->toBe(1)
        ->and($fresh->last_decline_code)->toBe('do_not_honor')
        ->and(freshLinkOf($attempt)->status)->toBe(PaymentLinkStatus::Active);
});

it('ignores payments the platform did not create, with a reduced payload and no call to Stripe (14.4)', function (): void {
    [, $attempt] = webhookAttempt();

    postPaymentEvent(paymentEvent('payment_intent.succeeded', 'evt_Foreign01', 'pi_ForeignSale', []));

    $stored = ProviderEvent::query()->withoutGlobalScopes()->sole();

    expect($stored->status)->toBe(ProviderEventStatus::Ignored)
        ->and($stored->last_error)->toBe(ProviderEventStatus::FOREIGN_OBJECT)
        ->and($stored->payload)->not->toContain('payer@example.com')
        ->and(jsonArray($stored->payload)['axispay_reduced'] ?? null)->toBeTrue()
        ->and(stripeHttp()->requests)->toBe([])
        ->and(freshAttempt($attempt)->status)->toBe(PaymentAttemptStatus::Processing);
});

it('ignores an event whose metadata names no attempt of the tenant', function (): void {
    webhookAttempt();

    postPaymentEvent(paymentEvent('payment_intent.succeeded', 'evt_Unknown01', 'pi_Other', ['axispay_attempt_id' => '01K6ZZZZZZZZZZZZZZZZZZZZZZ']));

    $stored = ProviderEvent::query()->withoutGlobalScopes()->sole();

    expect($stored->status)->toBe(ProviderEventStatus::Ignored)
        ->and($stored->last_error)->toBe(ProviderEventStatus::FOREIGN_OBJECT)
        ->and(stripeHttp()->requests)->toBe([]);
});
