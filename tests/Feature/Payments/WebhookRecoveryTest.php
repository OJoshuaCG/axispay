<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Data\ProviderPaymentFailure;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayConnectionResolver;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\ApplyProviderPayment;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\PaymentSnapshot;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Jobs\ProcessProviderEventJob;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Models\DomainEvent;
use Database\Factories\GatewayConnectionFactory;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckoutTestHelpers as Checkout;
use Tests\Support\FakePaymentGateway;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\call;
use function Pest\Laravel\travel;

/**
 * ADR-0051, Phase 4 iteration 4 (webhooks and reconciliation): recovery of
 * events stuck in `received`, unroutable events routed once their connection
 * exists, operator retries, fail-fast gateway errors, the capture window,
 * frozen outgoing-event snapshots, a success after close, routing by the
 * attempt's own connection and the adoption window.
 */
beforeEach(function (): void {
    Notification::fake();
});

/**
 * @param  array<string, mixed>  $object
 */
function recoveryPost(string $eventId, string $type, ?string $account, array $object, ?string $path = null): void
{
    $body = (string) json_encode(['id' => $eventId, 'type' => $type, 'account' => $account, 'livemode' => false, 'data' => ['object' => $object]]);

    call('POST', apiUrl($path ?? '/webhooks/stripe/connect/test'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => FakePaymentGateway::SIGNATURE, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
}

function recoveryPaymentEvent(PaymentLink $link, PaymentAttempt $attempt, string $eventId, string $type = 'payment_intent.succeeded', ?string $path = null, ?string $account = null): void
{
    recoveryPost($eventId, $type, $account ?? Checkout::connectionOf($link)->provider_account_id, [
        'id' => $attempt->provider_payment_id, 'object' => 'payment_intent', 'metadata' => ['axispay_attempt_id' => $attempt->id],
    ], $path);
}

function recoveryEvent(string $eventId): ProviderEvent
{
    return ProviderEvent::query()->withoutGlobalScopes()->where('provider_event_id', $eventId)->sole();
}

function recoveryAge(string $eventId, int $minutes): void
{
    ProviderEvent::query()->withoutGlobalScopes()->where('provider_event_id', $eventId)->update(['received_at' => now()->subMinutes($minutes)]);
}

function recoveryAudits(AuditAction $action): int
{
    return AuditLog::query()->withoutGlobalScopes()->where('action', $action->value)->count();
}

/**
 * @return array{0: PaymentLink, 1: PaymentAttempt, 2: FakePaymentGateway}
 */
function recoveryProcessingAttempt(?Closure $connection = null): array
{
    [, $link, $fake] = Checkout::scenario(connection: $connection);
    Checkout::pay($link, 'ctoken_processing');
    [$attempt] = Checkout::attempts($link);

    return [$link, $attempt, $fake];
}

// H1 -----------------------------------------------------------------------

it('queues again an event stuck in received when it is delivered again late (H1)', function (): void {
    Queue::fake([ProcessProviderEventJob::class]);
    [$link, $attempt] = recoveryProcessingAttempt();

    recoveryPaymentEvent($link, $attempt, 'evt_Stuck0001');
    recoveryPaymentEvent($link, $attempt, 'evt_Stuck0001');
    Queue::assertPushed(ProcessProviderEventJob::class, 1);

    recoveryAge('evt_Stuck0001', 10);
    recoveryPaymentEvent($link, $attempt, 'evt_Stuck0001');

    Queue::assertPushed(ProcessProviderEventJob::class, 2);
    expect(ProviderEvent::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('sweeps events stuck in received, leaves recent ones, and is scheduled every five minutes (H1)', function (): void {
    Queue::fake([ProcessProviderEventJob::class]);
    [$link, $attempt] = recoveryProcessingAttempt();
    recoveryPaymentEvent($link, $attempt, 'evt_Sweep0001');
    recoveryPaymentEvent($link, $attempt, 'evt_Sweep0002', 'payment_intent.processing');
    recoveryAge('evt_Sweep0001', 10);

    artisanCommand('axispay:provider-events:sweep')->assertSuccessful();

    Queue::assertPushed(ProcessProviderEventJob::class, 3);
    Queue::assertPushed(ProcessProviderEventJob::class, static fn (ProcessProviderEventJob $job): bool => $job->providerEventId === recoveryEvent('evt_Sweep0001')->id);
    expect(recoveryAudits(AuditAction::ProviderEventRetried))->toBe(1);

    $events = array_values(array_filter(app(Schedule::class)->events(), static fn (Event $event): bool => str_contains((string) $event->command, 'axispay:provider-events:sweep')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/5 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

// L3, M4, H1 (retry) ---------------------------------------------------------

it('fails a gateway 4xx at once, alerts, and lets the operator retry it once (L3, M4, H1)', function (): void {
    [$link, $attempt, $fake] = recoveryProcessingAttempt();
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);
    $fake->failNext('retrievePayment', new GatewayRequestException('No such payment.', 'resource_missing', null, 404));
    $log = captureDefaultLog();
    $reads = count($fake->callsTo('retrievePayment'));

    recoveryPaymentEvent($link, $attempt, 'evt_Retry0001');

    $event = recoveryEvent('evt_Retry0001');

    expect($event->status)->toBe(ProviderEventStatus::Failed)
        ->and($event->last_error)->toContain('GatewayRequestException')
        ->and($fake->callsTo('retrievePayment'))->toHaveCount($reads + 1)
        ->and(recoveryAudits(AuditAction::ProviderEventFailed))->toBe(1)
        ->and(collect($log->getRecords())->contains(static fn ($record): bool => $record->level->getName() === 'ALERT' && $record->message === 'A gateway event failed.'))->toBeTrue();

    artisanCommand('axispay:provider-events:retry '.$event->id)->assertSuccessful();
    artisanCommand('axispay:provider-events:retry '.$event->id)->assertSuccessful();

    expect(recoveryEvent('evt_Retry0001')->status)->toBe(ProviderEventStatus::Processed)
        ->and(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(recoveryAudits(AuditAction::ProviderEventRetried))->toBe(1);
});

it('retries every failed event with --failed and refuses a call without a target', function (): void {
    [$link, $attempt, $fake] = recoveryProcessingAttempt();
    $fake->failNext('retrievePayment', new GatewayRequestException('Bad request.', 'parameter_invalid', null, 400));
    recoveryPaymentEvent($link, $attempt, 'evt_RetryAll01');
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);

    artisanCommand('axispay:provider-events:retry')->assertExitCode(2);
    artisanCommand('axispay:provider-events:retry --failed')->assertSuccessful();

    expect(recoveryEvent('evt_RetryAll01')->status)->toBe(ProviderEventStatus::Processed)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

// M2 -----------------------------------------------------------------------

it('marks an api_key connection invalid when a payment read is refused and fails the event without retrying (M2)', function (): void {
    [$link, $attempt, $fake] = recoveryProcessingAttempt(static fn (GatewayConnectionFactory $factory): GatewayConnectionFactory => $factory->apiKey());
    $fake->failNext('retrievePayment', new GatewayAuthenticationException('Refused.', null, null, 401));
    $reads = count($fake->callsTo('retrievePayment'));
    $connection = Checkout::connectionOf($link);

    recoveryPaymentEvent($link, $attempt, 'evt_Auth0001', path: '/webhooks/stripe/direct/'.$connection->id);

    expect(recoveryEvent('evt_Auth0001')->status)->toBe(ProviderEventStatus::Failed)
        ->and($fake->callsTo('retrievePayment'))->toHaveCount($reads + 1)
        ->and(Checkout::connectionOf($link)->status)->toBe(ConnectionStatus::InvalidCredentials);
});

// H2 / M4: unroutable events ------------------------------------------------

it('replaces an unroutable event by its routed copy when it arrives again once routable (H2)', function (): void {
    FakePaymentGateway::install()->withAccount('acct_Later00001');
    recoveryPost('evt_Later0001', 'account.updated', 'acct_Later00001', ['id' => 'acct_Later00001', 'object' => 'account']);
    expect(recoveryEvent('evt_Later0001')->status)->toBe(ProviderEventStatus::Unroutable);

    $connection = GatewayTestHelpers::connection(Tenant::factory()->create(), state: static fn ($f) => $f->state(['provider_account_id' => 'acct_Later00001']));
    recoveryPost('evt_Later0001', 'account.updated', 'acct_Later00001', ['id' => 'acct_Later00001', 'object' => 'account']);

    $event = recoveryEvent('evt_Later0001');

    expect($event->tenant_id)->toBe($connection->tenant_id)
        ->and($event->gateway_connection_id)->toBe($connection->id)
        ->and($event->status)->toBe(ProviderEventStatus::Processed);
});

it('routes an unroutable event with the sweeper once its connection exists (M4)', function (): void {
    FakePaymentGateway::install()->withAccount('acct_Sweep00001');
    recoveryPost('evt_Reroute001', 'account.updated', 'acct_Sweep00001', ['id' => 'acct_Sweep00001', 'object' => 'account']);

    artisanCommand('axispay:provider-events:sweep')->assertSuccessful();
    expect(recoveryEvent('evt_Reroute001')->status)->toBe(ProviderEventStatus::Unroutable);

    $connection = GatewayTestHelpers::connection(Tenant::factory()->create(), state: static fn ($f) => $f->state(['provider_account_id' => 'acct_Sweep00001']));
    artisanCommand('axispay:provider-events:sweep')->assertSuccessful();

    $event = recoveryEvent('evt_Reroute001');

    expect($event->tenant_id)->toBe($connection->tenant_id)
        ->and($event->status)->toBe(ProviderEventStatus::Processed)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::ProviderEventRetried->value)->where('tenant_id', $connection->tenant_id)->count())->toBe(1);
});

// M3: capture window ----------------------------------------------------------

/**
 * @return array{0: PaymentLink, 1: PaymentAttempt, 2: FakePaymentGateway}
 */
function recoveryAuthorized(): array
{
    [, $link, $fake] = Checkout::scenario();
    // The capture call never reaches the gateway: the authorization stays.
    $fake->failNext('capturePayment', new GatewayUnavailableException('Fake: timeout.'));
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);
    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    return [$link, $attempt, $fake];
}

it('captures an authorization reported within the capture window (M3)', function (): void {
    [$link, $attempt] = recoveryAuthorized();

    travel(5)->minutes();
    recoveryPaymentEvent($link, $attempt, 'evt_Window001', 'payment_intent.amount_capturable_updated');

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

it('voids an authorization reported after the capture window (M3)', function (): void {
    [$link, $attempt, $fake] = recoveryAuthorized();
    $captures = count($fake->callsTo('capturePayment'));

    travel(16)->minutes();
    recoveryPaymentEvent($link, $attempt, 'evt_Window002', 'payment_intent.amount_capturable_updated');

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->callsTo('capturePayment'))->toHaveCount($captures)
        ->and($fake->callsTo('cancelPayment'))->toHaveCount(1)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PaymentAuthorizationVoided->value)->sole()->changes['reason'] ?? null)->toBe('capture_window_elapsed');
});

it('lets a payment the gateway already captured win after the capture window (M3)', function (): void {
    [$link, $attempt, $fake] = recoveryAuthorized();
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);

    travel(30)->minutes();
    recoveryPaymentEvent($link, $attempt, 'evt_Window003');

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($fake->callsTo('cancelPayment'))->toBe([])
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

// M6: frozen snapshots ------------------------------------------------------

/**
 * @return list<DomainEvent>
 */
function recoveryDomainEvents(PaymentLink $link, DomainEventType $type): array
{
    return array_values(Checkout::inTenant($link, static fn () => DomainEvent::query()->where('type', $type->value)->orderBy('id')->get()->all()));
}

it('freezes the payment and the link in payment.succeeded and payment_link.paid (M6)', function (): void {
    [, $link] = Checkout::scenario();
    Checkout::pay($link);

    [$succeeded] = recoveryDomainEvents($link, DomainEventType::PaymentSucceeded);
    [$paid] = recoveryDomainEvents($link, DomainEventType::PaymentLinkPaid);

    expect(data_get($succeeded->data, 'payment.object'))->toBe('payment')
        ->and(data_get($succeeded->data, 'payment.id'))->toStartWith('pay_')
        ->and(data_get($succeeded->data, 'payment.status'))->toBe('succeeded')
        ->and(data_get($succeeded->data, 'payment.amount'))->toBeString()
        ->and(data_get($succeeded->data, 'payment.amount_minor'))->toBe($link->amount_minor)
        ->and(data_get($succeeded->data, 'payment.currency'))->toBe($link->currency->value)
        ->and(data_get($succeeded->data, 'payment.late_payment'))->toBeFalse()
        ->and(data_get($paid->data, 'payment_link.object'))->toBe('payment_link')
        ->and(data_get($paid->data, 'payment_link.status'))->toBe('paid')
        ->and(data_get($paid->data, 'payment_link.id'))->toBe($link->prefixedId())
        ->and((string) json_encode($succeeded->data))->not->toContain('pi_');
});

it('carries a generic failure code and the amount in payment.failed, never the raw decline code (M6)', function (): void {
    [, $link] = Checkout::scenario();
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_decline');

    [$failed] = recoveryDomainEvents($link, DomainEventType::PaymentFailed);

    expect($failed->data['failure_code'] ?? null)->toBe('card_declined')
        ->and($failed->data['failure_count'] ?? null)->toBe(1)
        ->and(data_get($failed->data, 'payment.amount_minor'))->toBe($link->amount_minor)
        ->and(data_get($failed->data, 'payment.currency'))->toBe($link->currency->value)
        ->and(data_get($failed->data, 'payment.status'))->toBe('requires_payment_method')
        ->and($failed->data)->not->toHaveKey('decline_code')
        ->and((string) json_encode($failed->data))->not->toContain('generic_decline');
});

it('records payment.processing once when a payment enters processing (M6)', function (): void {
    [$link, $attempt] = recoveryProcessingAttempt();
    Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Webhook));

    $events = recoveryDomainEvents($link, DomainEventType::PaymentProcessing);

    expect($events)->toHaveCount(1)
        ->and(data_get($events[0]->data, 'payment.status'))->toBe('processing');
});

it('maps gateway failure codes to generic ones (M6)', function (?string $code, ?string $decline, ?string $expected): void {
    expect(PaymentSnapshot::genericFailureCode($code, $decline))->toBe($expected);
})->with([
    [null, null, null],
    ['card_declined', 'insufficient_funds', 'insufficient_funds'],
    ['card_declined', 'fraudulent', 'card_declined'],
    ['card_declined', 'stolen_card', 'card_declined'],
    ['incorrect_cvc', null, 'incorrect_card_details'],
    ['expired_card', null, 'expired_card'],
    ['payment_intent_authentication_failure', null, 'authentication_failed'],
    ['processing_error', null, 'processing_error'],
]);

// L1 -----------------------------------------------------------------------

it('flags a closed attempt whose payment later succeeds for review, once (L1)', function (): void {
    [, $link, $fake] = Checkout::scenario();
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_decline');
    [$attempt] = Checkout::attempts($link);
    Checkout::inTenant($link, static fn () => PaymentAttempt::query()->whereKey($attempt->id)->update(['status' => PaymentAttemptStatus::Canceled->value]));
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);

    recoveryPaymentEvent($link, $attempt, 'evt_Closed001');
    recoveryPaymentEvent($link, $attempt, 'evt_Closed002');

    $fresh = Checkout::attempts($link)[0];

    expect($fresh->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fresh->needs_review)->toBeTrue()
        ->and($fresh->review_reason)->toBe(ApplyProviderPayment::SUCCEEDED_AFTER_CLOSE)
        ->and(recoveryAudits(AuditAction::PaymentNeedsReview))->toBe(1);
});

// L4 -----------------------------------------------------------------------

it('never hands an event to a disconnected connection of another tenant (L4)', function (): void {
    $resolver = app(GatewayConnectionResolver::class);
    $first = GatewayTestHelpers::connection(Tenant::factory()->create(), state: static fn ($f) => $f->disconnected()->state(['provider_account_id' => 'acct_Shared00001']));

    expect($resolver->forProviderAccount($first->provider, 'acct_Shared00001', false)?->id)->toBe($first->id);

    GatewayTestHelpers::connection(Tenant::factory()->create(), state: static fn ($f) => $f->disconnected()->state(['provider_account_id' => 'acct_Shared00001']));

    expect($resolver->forProviderAccount($first->provider, 'acct_Shared00001', false))->toBeNull();
});

it('routes a payment event to the connection of its attempt (L4)', function (): void {
    Queue::fake([ProcessProviderEventJob::class]);
    [$link, $attempt] = recoveryProcessingAttempt();
    $own = Checkout::connectionOf($link);
    Checkout::inTenant($link, static fn () => GatewayConnection::query()->whereKey($own->id)->update(['status' => ConnectionStatus::Disconnected->value, 'disconnected_at' => now()]));
    $other = GatewayTestHelpers::connection(Tenant::factory()->create(), state: static fn ($f) => $f->state(['provider_account_id' => $own->provider_account_id]));

    recoveryPaymentEvent($link, $attempt, 'evt_Route0001', account: $own->provider_account_id);

    $event = recoveryEvent('evt_Route0001');

    expect($event->tenant_id)->toBe($link->tenant_id)
        ->and($event->gateway_connection_id)->toBe($own->id)
        ->and($event->tenant_id)->not->toBe($other->tenant_id);
});

// L5 -----------------------------------------------------------------------

it('adopts a payment by its metadata only when it was created while its attempt could create it (L5)', function (int $createdHoursAgo, bool $adopted): void {
    [, $link, $fake] = Checkout::scenario();
    $attempt = Checkout::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus(PaymentAttemptStatus::RequiresConfirmation)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => Checkout::connectionOf($link)->id,
        'provider_payment_id' => null,
    ]));
    $fake->seedPayment('pi_Adopt0001', ProviderPaymentStatus::Processing, $attempt->amount_minor, $attempt->currency->value, $attempt->id, now()->subHours($createdHoursAgo)->getTimestamp());

    Checkout::inTenant($link, static fn () => app(SyncPaymentAttempt::class)->handle($attempt->id, SyncReason::Webhook, providerPaymentId: 'pi_Adopt0001'));

    expect(Checkout::inTenant($link, static fn () => PaymentAttempt::query()->findOrFail($attempt->id)->provider_payment_id))->toBe($adopted ? 'pi_Adopt0001' : null);
})->with([
    'created with the attempt' => [0, true],
    'created days before the attempt' => [72, false],
]);

it('keeps ProviderPaymentFailure data internal to the attempt', function (): void {
    [, $link, $fake] = Checkout::scenario();
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_processing');
    [$attempt] = Checkout::attempts($link);
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::RequiresPaymentMethod, new ProviderPaymentFailure('ch_Lost0001', 'card_declined', 'lost_card', 'Your card was declined.'));

    recoveryPaymentEvent($link, $attempt, 'evt_Lost0001', 'payment_intent.payment_failed');

    [$failed] = recoveryDomainEvents($link, DomainEventType::PaymentFailed);

    expect(Checkout::attempts($link)[0]->last_decline_code)->toBe('lost_card')
        ->and($failed->data['failure_code'] ?? null)->toBe('card_declined')
        ->and((string) json_encode($failed->data))->not->toContain('lost_card');
});
