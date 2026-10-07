<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\DisputeState;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\RefundOrigin;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Payments\Models\Dispute;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Models\WebhookEvent;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApiTestHelpers;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeFixtures;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\call;

/**
 * CRX-14 (spec B10, plan 14.3, 16.1, 16.2): Stripe's `charge.refunded`,
 * `refund.*` and `charge.dispute.*` events are ingested like the payment
 * ones: verified, stored once, re-read from Stripe (ADR-017, never trusted
 * from the payload) and applied to the payment, its refunds and disputes and
 * its link, telling the integrator with `refund.created|succeeded|failed` and
 * `dispute.created|closed`. A refund made in Stripe's Dashboard is imported
 * (origin `provider_dashboard`). Platform fees are not reversed (ADR-0012).
 */
beforeEach(function (): void {
    Notification::fake();
});

/**
 * A captured payment (USD 1,500.00) on a paid link, of a tenant with an
 * endpoint that receives every webhook.
 *
 * @return array{0: PaymentLink, 1: PaymentAttempt}
 */
function capturedPayment(): array
{
    $tenant = Tenant::factory()->create();
    $connection = GatewayTestHelpers::connection($tenant, false, static fn ($f) => $f->state(['provider_account_id' => 'acct_Hooks0001']));
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->inStatus(PaymentLinkStatus::Paid)->state(['client_reference_id' => 'ORDER-77']));
    WebhookTestHelpers::endpoint($tenant);

    $attempt = app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus(PaymentAttemptStatus::Succeeded)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => $connection->id,
        'provider_account_id' => 'acct_Hooks0001',
        'provider_payment_id' => 'pi_Cap'.substr(bin2hex(random_bytes(6)), 0, 10),
        'succeeded_at' => now(),
    ]));

    return [$link, $attempt];
}

/**
 * A refund pending at our side, as the API leaves it before the gateway answers.
 */
function pendingRefund(PaymentAttempt $attempt, int $amount = 50_000, ?string $providerRefundId = null): Refund
{
    return app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn (): Refund => Refund::factory()->createOne([
        'payment_attempt_id' => $attempt->id,
        'amount_minor' => $amount,
        'currency' => 'USD',
        'provider_refund_id' => $providerRefundId,
    ]));
}

/**
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function stripeEvent(string $type, string $eventId, array $object): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => StripeFixtures::API_VERSION,
        'account' => 'acct_Hooks0001',
        'created' => 1790003000,
        'livemode' => false,
        'type' => $type,
        'data' => ['object' => $object],
    ];
}

/**
 * @param  array<mixed>  $event
 */
function deliver(array $event): void
{
    [$body, $signature] = StripeFixtures::signed($event, 'whsec_testconnectsecret');
    call('POST', apiUrl('/webhooks/stripe/connect/test'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
}

/**
 * @return list<Refund>
 */
function refundsOfAttempt(PaymentAttempt $attempt): array
{
    return app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn (): array => array_values(Refund::query()->orderBy('id')->get()->all()));
}

/**
 * @return list<Dispute>
 */
function disputesOfAttempt(PaymentAttempt $attempt): array
{
    return app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn (): array => array_values(Dispute::query()->orderBy('id')->get()->all()));
}

function reloadAttempt(PaymentAttempt $attempt): PaymentAttempt
{
    return app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn (): PaymentAttempt => PaymentAttempt::query()->findOrFail($attempt->id));
}

function reloadLink(PaymentAttempt $attempt): PaymentLink
{
    return app(TenantContext::class)->runAsTenant($attempt->tenant_id, false, static fn (): PaymentLink => PaymentLink::query()->findOrFail($attempt->payment_link_id));
}

/**
 * @return list<array<mixed>> webhook bodies of a type for the attempt's tenant, oldest first
 */
function sentBodies(PaymentAttempt $attempt, WebhookEventType $type): array
{
    $events = WebhookTestHelpers::in($attempt->tenant_id, false, static fn (): array => WebhookEvent::query()->orderBy('id')->get()->all());

    return array_values(array_map(
        static fn (WebhookEvent $event): array => jsonArray($event->payload),
        array_filter($events, static fn (WebhookEvent $event): bool => $event->type === $type),
    ));
}

/**
 * @return list<ProviderEvent>
 */
function providerEvents(): array
{
    return array_values(ProviderEvent::query()->withoutGlobalScopes()->orderBy('received_at')->get()->all());
}

it('re-reads a refund of ours when Stripe reports it, completes it and tells the integrator, once', function (): void {
    [$link, $attempt] = capturedPayment();
    $refund = pendingRefund($attempt, 50_000, 're_Ours0001');
    stripeHttp()->on('get', '/v1/refunds/re_Ours0001', StripeFixtures::refund('re_Ours0001', 'succeeded', (string) $attempt->provider_payment_id, $refund->id));
    $event = stripeEvent('refund.updated', 'evt_RefUpd0001', ['id' => 're_Ours0001', 'object' => 'refund', 'payment_intent' => $attempt->provider_payment_id, 'status' => 'pending']);

    deliver($event);
    deliver($event);
    deliver(stripeEvent('refund.updated', 'evt_RefUpd0002', ['id' => 're_Ours0001', 'object' => 'refund', 'payment_intent' => $attempt->provider_payment_id, 'status' => 'succeeded']));

    $stored = providerEvents();

    expect($stored)->toHaveCount(2)
        ->and($stored[0]->status)->toBe(ProviderEventStatus::Processed)
        ->and($stored[0]->payment_attempt_id)->toBe($attempt->id)
        ->and(refundsOfAttempt($attempt))->toHaveCount(1)
        ->and(refundsOfAttempt($attempt)[0]->status)->toBe(RefundState::Succeeded)
        ->and(refundsOfAttempt($attempt)[0]->origin)->toBe(RefundOrigin::Api)
        ->and(reloadAttempt($attempt)->amount_refunded_minor)->toBe(50000)
        ->and(reloadLink($attempt)->refund_status->value)->toBe('partial')
        // Applying the same Stripe state twice announces it once.
        ->and(sentBodies($attempt, WebhookEventType::RefundSucceeded))->toHaveCount(1)
        ->and(sentBodies($attempt, WebhookEventType::RefundCreated))->toBe([]);
});

it('imports a refund made in the Stripe Dashboard, with refund.created and refund.succeeded', function (): void {
    [, $attempt] = capturedPayment();
    stripeHttp()->on('get', '/v1/refunds', ['object' => 'list', 'has_more' => false, 'data' => [
        StripeFixtures::refund('re_Dash0001', 'succeeded', (string) $attempt->provider_payment_id, changes: ['amount' => 30_000]),
    ]]);

    deliver(stripeEvent('charge.refunded', 'evt_ChgRef0001', ['id' => 'ch_FakeCharge0001', 'object' => 'charge', 'payment_intent' => $attempt->provider_payment_id, 'refunded' => false, 'amount_refunded' => 30000]));
    // Another delivery for the same state changes nothing.
    deliver(stripeEvent('charge.refunded', 'evt_ChgRef0002', ['id' => 'ch_FakeCharge0001', 'object' => 'charge', 'payment_intent' => $attempt->provider_payment_id, 'refunded' => false, 'amount_refunded' => 30000]));

    $refunds = refundsOfAttempt($attempt);
    $created = sentBodies($attempt, WebhookEventType::RefundCreated);
    $succeeded = sentBodies($attempt, WebhookEventType::RefundSucceeded);

    expect($refunds)->toHaveCount(1)
        ->and($refunds[0]->origin)->toBe(RefundOrigin::ProviderDashboard)
        ->and($refunds[0]->status)->toBe(RefundState::Succeeded)
        ->and($refunds[0]->amount_minor)->toBe(30000)
        ->and($refunds[0]->provider_refund_id)->toBe('re_Dash0001')
        ->and(reloadAttempt($attempt)->amount_refunded_minor)->toBe(30000)
        ->and(reloadLink($attempt)->refund_status->value)->toBe('partial')
        ->and($created)->toHaveCount(1)
        ->and($succeeded)->toHaveCount(1)
        ->and(data_get($created[0], 'data.object.origin'))->toBe('provider_dashboard')
        ->and(data_get($succeeded[0], 'data.payment.client_reference_id'))->toBe('ORDER-77')
        ->and(json_encode([$created, $succeeded]))->not->toContain('re_Dash0001')
        ->and(json_encode([$created, $succeeded]))->not->toContain((string) $attempt->provider_payment_id);
});

it('marks the link fully refunded when the refunds add up to the payment', function (): void {
    [, $attempt] = capturedPayment();
    stripeHttp()->on('get', '/v1/refunds', ['object' => 'list', 'has_more' => false, 'data' => [
        StripeFixtures::refund('re_Full0001', 'succeeded', (string) $attempt->provider_payment_id, changes: ['amount' => 100_000]),
        StripeFixtures::refund('re_Full0002', 'succeeded', (string) $attempt->provider_payment_id, changes: ['amount' => 50_000]),
    ]]);

    deliver(stripeEvent('charge.refunded', 'evt_ChgRefFull', ['id' => 'ch_FakeCharge0001', 'object' => 'charge', 'payment_intent' => $attempt->provider_payment_id]));

    expect(reloadAttempt($attempt)->amount_refunded_minor)->toBe(150000)
        ->and(reloadLink($attempt)->refund_status->value)->toBe('full');
});

it('records a refund that failed, frees the balance and tells the integrator', function (): void {
    [, $attempt] = capturedPayment();
    $refund = pendingRefund($attempt, 50_000, 're_Fail0001');
    stripeHttp()->on('get', '/v1/refunds/re_Fail0001', StripeFixtures::refund('re_Fail0001', 'failed', (string) $attempt->provider_payment_id, $refund->id, ['failure_reason' => 'lost_or_stolen_card']));

    deliver(stripeEvent('refund.failed', 'evt_RefFail0001', ['id' => 're_Fail0001', 'object' => 'refund', 'payment_intent' => $attempt->provider_payment_id, 'status' => 'failed']));

    $failed = sentBodies($attempt, WebhookEventType::RefundFailed);

    expect(refundsOfAttempt($attempt)[0]->status)->toBe(RefundState::Failed)
        ->and(reloadAttempt($attempt)->amount_refunded_minor)->toBe(0)
        ->and(reloadLink($attempt)->refund_status->value)->toBe('none')
        ->and($failed)->toHaveCount(1)
        // A generic code: Stripe's own failure reason stays internal.
        ->and(data_get($failed[0], 'data.object.failure.code'))->toBe('refund_failed')
        ->and(json_encode($failed))->not->toContain('lost_or_stolen_card');
});

it('takes back the refunded amount when a refund that had succeeded fails later', function (): void {
    [, $attempt] = capturedPayment();
    $refund = pendingRefund($attempt, 50_000, 're_Late0001');
    stripeHttp()->on('get', '/v1/refunds/re_Late0001', StripeFixtures::refund('re_Late0001', 'succeeded', (string) $attempt->provider_payment_id, $refund->id));
    deliver(stripeEvent('refund.updated', 'evt_RefLate0001', ['id' => 're_Late0001', 'object' => 'refund', 'payment_intent' => $attempt->provider_payment_id]));
    expect(reloadAttempt($attempt)->amount_refunded_minor)->toBe(50000);

    stripeHttp()->on('get', '/v1/refunds/re_Late0001', StripeFixtures::refund('re_Late0001', 'failed', (string) $attempt->provider_payment_id, $refund->id));
    deliver(stripeEvent('refund.failed', 'evt_RefLate0002', ['id' => 're_Late0001', 'object' => 'refund', 'payment_intent' => $attempt->provider_payment_id]));

    expect(refundsOfAttempt($attempt)[0]->status)->toBe(RefundState::Failed)
        ->and(reloadAttempt($attempt)->amount_refunded_minor)->toBe(0)
        ->and(reloadLink($attempt)->refund_status->value)->toBe('none');
});

it('never moves a refund back after Stripe reported it failed or canceled', function (): void {
    [, $attempt] = capturedPayment();
    $refund = pendingRefund($attempt, 50_000, 're_Term0001');
    stripeHttp()->on('get', '/v1/refunds/re_Term0001', StripeFixtures::refund('re_Term0001', 'canceled', (string) $attempt->provider_payment_id, $refund->id));
    deliver(stripeEvent('refund.updated', 'evt_RefTerm0001', ['id' => 're_Term0001', 'object' => 'refund', 'payment_intent' => $attempt->provider_payment_id]));

    stripeHttp()->on('get', '/v1/refunds/re_Term0001', StripeFixtures::refund('re_Term0001', 'succeeded', (string) $attempt->provider_payment_id, $refund->id));
    deliver(stripeEvent('refund.updated', 'evt_RefTerm0002', ['id' => 're_Term0001', 'object' => 'refund', 'payment_intent' => $attempt->provider_payment_id]));

    expect(refundsOfAttempt($attempt)[0]->status)->toBe(RefundState::Canceled)
        ->and(reloadAttempt($attempt)->amount_refunded_minor)->toBe(0)
        ->and(sentBodies($attempt, WebhookEventType::RefundFailed))->toHaveCount(1);
});

it('ignores refund and dispute events about payments the platform did not make, without calling Stripe', function (string $type, string $objectType): void {
    capturedPayment();

    deliver(stripeEvent($type, 'evt_Foreign'.substr(md5($type), 0, 6), ['id' => 'obj_Foreign0001', 'object' => $objectType, 'payment_intent' => 'pi_NotOurs0001', 'status' => 'succeeded']));

    $stored = providerEvents();

    expect($stored)->toHaveCount(1)
        ->and($stored[0]->status)->toBe(ProviderEventStatus::Ignored)
        ->and($stored[0]->last_error)->toBe(ProviderEventStatus::FOREIGN_OBJECT)
        ->and(stripeHttp()->requests)->toBe([]);
})->with([
    'refund' => ['refund.created', 'refund'],
    'charge refunded' => ['charge.refunded', 'charge'],
    'dispute' => ['charge.dispute.created', 'dispute'],
]);

it('stores the refund and dispute events with the reduced payload only', function (): void {
    [, $attempt] = capturedPayment();
    stripeHttp()->on('get', '/v1/disputes/dp_Reduced01', StripeFixtures::dispute('dp_Reduced01', 'needs_response', (string) $attempt->provider_payment_id));

    deliver(stripeEvent('charge.dispute.created', 'evt_DispRed0001', ['id' => 'dp_Reduced01', 'object' => 'dispute', 'payment_intent' => $attempt->provider_payment_id, 'evidence' => ['customer_email_address' => 'payer@example.com']]));

    $payload = providerEvents()[0]->payload;

    expect($payload)->not->toContain('payer@example.com')
        ->and($payload)->toContain('axispay_reduced');
});

it('records a dispute, marks the payment and its link disputed and tells the integrator', function (): void {
    [, $attempt] = capturedPayment();
    stripeHttp()->on('get', '/v1/disputes/dp_Open0001', StripeFixtures::dispute('dp_Open0001', 'needs_response', (string) $attempt->provider_payment_id));
    $event = stripeEvent('charge.dispute.created', 'evt_DispNew0001', ['id' => 'dp_Open0001', 'object' => 'dispute', 'payment_intent' => $attempt->provider_payment_id, 'status' => 'needs_response']);

    deliver($event);
    deliver($event);

    $disputes = disputesOfAttempt($attempt);
    $created = sentBodies($attempt, WebhookEventType::DisputeCreated);

    expect($disputes)->toHaveCount(1)
        ->and($disputes[0]->status)->toBe(DisputeState::NeedsResponse)
        ->and($disputes[0]->amount_minor)->toBe(150000)
        ->and($disputes[0]->reason)->toBe('fraudulent')
        ->and($disputes[0]->evidence_due_by?->getTimestamp())->toBe(1790600000)
        ->and(reloadLink($attempt)->dispute_status->value)->toBe('open')
        ->and($created)->toHaveCount(1)
        ->and(data_get($created[0], 'data.object.object'))->toBe('dispute')
        ->and(data_get($created[0], 'data.object.payment'))->toBe($attempt->prefixedId())
        ->and(data_get($created[0], 'data.object.status'))->toBe('needs_response')
        ->and(data_get($created[0], 'data.object.amount'))->toBe('1500.00')
        ->and(data_get($created[0], 'data.object.evidence_due_by'))->not->toBeNull()
        ->and(data_get($created[0], 'data.payment.dispute_status'))->toBe('open')
        ->and(data_get($created[0], 'data.payment.client_reference_id'))->toBe('ORDER-77')
        ->and(json_encode($created))->not->toContain('dp_Open0001')
        ->and(json_encode($created))->not->toContain((string) $attempt->provider_payment_id);
});

it('closes a dispute with its outcome and tells the integrator once', function (string $stripe, DisputeState $state, string $linkStatus): void {
    [, $attempt] = capturedPayment();
    stripeHttp()->on('get', '/v1/disputes/dp_Close0001', StripeFixtures::dispute('dp_Close0001', 'needs_response', (string) $attempt->provider_payment_id));
    deliver(stripeEvent('charge.dispute.created', 'evt_DispC0001', ['id' => 'dp_Close0001', 'object' => 'dispute', 'payment_intent' => $attempt->provider_payment_id]));

    stripeHttp()->on('get', '/v1/disputes/dp_Close0001', StripeFixtures::dispute('dp_Close0001', $stripe, (string) $attempt->provider_payment_id));
    $closed = stripeEvent('charge.dispute.closed', 'evt_DispC0002', ['id' => 'dp_Close0001', 'object' => 'dispute', 'payment_intent' => $attempt->provider_payment_id]);
    deliver($closed);
    deliver(stripeEvent('charge.dispute.closed', 'evt_DispC0003', ['id' => 'dp_Close0001', 'object' => 'dispute', 'payment_intent' => $attempt->provider_payment_id]));

    $events = sentBodies($attempt, WebhookEventType::DisputeClosed);

    expect(disputesOfAttempt($attempt)[0]->status)->toBe($state)
        ->and(disputesOfAttempt($attempt)[0]->closed_at)->not->toBeNull()
        ->and(reloadLink($attempt)->dispute_status->value)->toBe($linkStatus)
        ->and($events)->toHaveCount(1)
        ->and(data_get($events[0], 'data.object.status'))->toBe($stripe)
        ->and(data_get($events[0], 'data.payment.dispute_status'))->toBe($linkStatus);
})->with([
    'won' => ['won', DisputeState::Won, 'won'],
    'lost' => ['lost', DisputeState::Lost, 'lost'],
    'an inquiry closed without a chargeback' => ['warning_closed', DisputeState::WarningClosed, 'won'],
]);

it('keeps the dispute open while it is under review', function (): void {
    [, $attempt] = capturedPayment();
    stripeHttp()->on('get', '/v1/disputes/dp_Rev0001', StripeFixtures::dispute('dp_Rev0001', 'needs_response', (string) $attempt->provider_payment_id));
    deliver(stripeEvent('charge.dispute.created', 'evt_DispR0001', ['id' => 'dp_Rev0001', 'object' => 'dispute', 'payment_intent' => $attempt->provider_payment_id]));

    stripeHttp()->on('get', '/v1/disputes/dp_Rev0001', StripeFixtures::dispute('dp_Rev0001', 'under_review', (string) $attempt->provider_payment_id));
    deliver(stripeEvent('charge.dispute.updated', 'evt_DispR0002', ['id' => 'dp_Rev0001', 'object' => 'dispute', 'payment_intent' => $attempt->provider_payment_id]));

    expect(disputesOfAttempt($attempt)[0]->status)->toBe(DisputeState::UnderReview)
        ->and(reloadLink($attempt)->dispute_status->value)->toBe('open')
        ->and(sentBodies($attempt, WebhookEventType::DisputeClosed))->toBe([]);
});

it('creates a dispute that is first seen already closed, announcing both events', function (): void {
    [, $attempt] = capturedPayment();
    stripeHttp()->on('get', '/v1/disputes/dp_Late0001', StripeFixtures::dispute('dp_Late0001', 'lost', (string) $attempt->provider_payment_id));

    deliver(stripeEvent('charge.dispute.closed', 'evt_DispL0001', ['id' => 'dp_Late0001', 'object' => 'dispute', 'payment_intent' => $attempt->provider_payment_id]));

    expect(sentBodies($attempt, WebhookEventType::DisputeCreated))->toHaveCount(1)
        ->and(sentBodies($attempt, WebhookEventType::DisputeClosed))->toHaveCount(1)
        ->and(reloadLink($attempt)->dispute_status->value)->toBe('lost');
});

it('does not adopt a refund of a payment other than the attempt it was found for', function (): void {
    [, $attempt] = capturedPayment();
    $refund = pendingRefund($attempt, 50_000, 're_Mismatch01');
    // Stripe says this refund belongs to another PaymentIntent: nothing is applied.
    stripeHttp()->on('get', '/v1/refunds/re_Mismatch01', StripeFixtures::refund('re_Mismatch01', 'succeeded', 'pi_SomethingElse01', $refund->id));

    deliver(stripeEvent('refund.updated', 'evt_RefMis0001', ['id' => 're_Mismatch01', 'object' => 'refund', 'payment_intent' => $attempt->provider_payment_id]));

    expect(refundsOfAttempt($attempt)[0]->status)->toBe(RefundState::Pending)
        ->and(reloadAttempt($attempt)->amount_refunded_minor)->toBe(0);
});
