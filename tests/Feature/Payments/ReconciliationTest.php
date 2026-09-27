<?php

declare(strict_types=1);

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Jobs\CloseAttemptOfClosedLinkJob;
use App\Modules\Payments\Jobs\CompleteAuthorizedPaymentJob;
use App\Modules\Payments\Jobs\ReconcilePaymentAttemptsJob;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ApiTestHelpers;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\travel;

/**
 * Plan 12.5 and ADR-0050: the reconciliation re-reads open attempts, voids
 * authorizations left uncaptured and releases links stuck in processing.
 * Plan 9.1: a link that expires or is canceled cancels its waiting payment.
 * Plan 26.2 case 17 for the new jobs.
 */
it('voids an authorization left uncaptured past the capture window', function (): void {
    [, $link, $fake] = Checkout::scenario();
    $fake->capturesAs(ProviderPaymentStatus::RequiresCapture); // the capture "never happened"
    Checkout::pay($link);
    [$attempt] = Checkout::attempts($link);
    // Simulate a flow that stopped between authorization and capture.
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::RequiresCapture);
    expect($attempt->status)->toBe(PaymentAttemptStatus::RequiresCapture);

    travel(20)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    $fresh = Checkout::attempts($link)[0];

    expect($fresh->status)->toBe(PaymentAttemptStatus::Canceled)
        ->and($fake->paymentStatus((string) $attempt->provider_payment_id))->toBe(ProviderPaymentStatus::Canceled)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', AuditAction::PaymentAuthorizationVoided->value)->count())->toBe(1);
});

it('applies a success the webhooks missed', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_processing');
    [$attempt] = Checkout::attempts($link);
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);

    travel(11)->minutes();
    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    expect(Checkout::attempts($link)[0]->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid);
});

it('leaves recent attempts alone', function (): void {
    [, $link, $fake] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_processing');
    $calls = count($fake->calls);

    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    expect($fake->calls)->toHaveCount($calls);
});

it('releases a link stuck in processing without a payment under way', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->processing()->state(['updated_at' => now()->subHour()]));

    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Active);
});

it('cancels the waiting gateway payment when the link expires or is canceled (plan 9.1)', function (string $how): void {
    [, $link, $fake] = Checkout::scenario();
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_decline');
    [$attempt] = Checkout::attempts($link);

    Checkout::inTenant($link, static function () use ($how, $link): void {
        if ($how === 'expire') {
            PaymentLink::query()->whereKey($link->id)->update(['expires_at' => now()->subMinute()]);
            app(ExpirePaymentLink::class)->handle($link->id);
        } else {
            app(CancelPaymentLink::class)->handle(Checkout::freshLink($link), new CancelPaymentLinkData('No longer needed'), Actor::system());
        }
    });

    $fresh = Checkout::attempts($link)[0];

    // One decline before closing: the attempt closes as failed (plan 9.2).
    expect($fresh->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($fake->callsTo('cancelPayment'))->toBe(['cancelPayment:'.$attempt->provider_payment_id]);
})->with(['expire', 'cancel']);

it('lets the payment win when the gateway says it succeeded as the link closes', function (): void {
    [, $link, $fake] = Checkout::scenario();
    config(['axispay.checkout.turnstile_after_failures' => 99]);
    Checkout::pay($link, 'ctoken_decline');
    [$attempt] = Checkout::attempts($link);
    $fake->setPaymentStatus((string) $attempt->provider_payment_id, ProviderPaymentStatus::Succeeded);

    Checkout::inTenant($link, static fn () => app(CancelPaymentLink::class)->handle(Checkout::freshLink($link), new CancelPaymentLinkData(null), Actor::system()));

    expect(Checkout::freshLink($link)->status)->toBe(PaymentLinkStatus::Paid)
        ->and(Checkout::attempts($link)[0]->late_payment)->toBeTrue();
});

it('fails the new tenant jobs that run without a tenant context (case 17)', function (string $job): void {
    [, $link] = Checkout::scenario();
    $attemptId = Checkout::inTenant($link, static fn (): string => PaymentAttempt::factory()->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => Checkout::connectionOf($link)->id,
    ])->id);

    app(TenantContext::class)->clear();

    expect(fn () => match ($job) {
        'reconcile' => new ReconcilePaymentAttemptsJob,
        'close' => new CloseAttemptOfClosedLinkJob($attemptId),
        default => new CompleteAuthorizedPaymentJob($attemptId),
    })->toThrow(MissingTenantContextException::class);

    // Constructed in context, the payload holds only identifiers.
    $payload = Checkout::inTenant($link, static fn () => serialize(new CloseAttemptOfClosedLinkJob($attemptId)));
    expect($payload)->toContain($attemptId);
    expect($payload)->not->toContain('ana@');
})->with(['reconcile', 'close', 'complete']);

it('queues one reconciliation per tenant and mode with open attempts', function (): void {
    Queue::fake();
    [, $link] = Checkout::scenario();
    Checkout::pay($link, 'ctoken_processing');

    artisanCommand('axispay:payments:reconcile')->assertSuccessful();

    Queue::assertPushed(ReconcilePaymentAttemptsJob::class, static fn (ReconcilePaymentAttemptsJob $job): bool => $job->tenantId() === $link->tenant_id && $job->livemode() === false);
});

it('is scheduled every 15 minutes on one server without overlapping', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'axispay:payments:reconcile'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('*/15 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('visits the oldest-visited payments under way first, within the batch, failures included (B2)', function (): void {
    [$tenant, $link, $fake] = Checkout::scenario();
    config(['axispay.payments.reconcile_batch_size' => 3]);
    $connection = Checkout::connectionOf($link);
    $stale = now()->subHour();
    $make = static function (PaymentAttemptStatus $status, ?CarbonImmutable $visited) use ($tenant, $connection, $stale): PaymentAttempt {
        $owner = ApiTestHelpers::link($tenant, false);

        return Checkout::inTenant($owner, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus($status)->createOne([
            'payment_link_id' => $owner->id,
            'gateway_connection_id' => $connection->id,
            'reconciled_at' => $visited,
            'updated_at' => $stale,
        ]));
    };

    // Rows visited recently whose reads fail (the gateway does not know them).
    $visited = CarbonImmutable::now()->subMinutes(2);
    $old = array_map(static fn (): PaymentAttempt => $make(PaymentAttemptStatus::RequiresCapture, $visited), range(1, 5));
    $new = $make(PaymentAttemptStatus::RequiresAction, null);
    $fake->seedPayment((string) $new->provider_payment_id, ProviderPaymentStatus::RequiresAction, $new->amount_minor, $new->currency->value, $new->id);
    $waiting = $make(PaymentAttemptStatus::RequiresPaymentMethod, null);

    Checkout::inTenant($link, static fn () => app()->call([new ReconcilePaymentAttemptsJob, 'handle']));

    $fresh = static fn (PaymentAttempt $attempt): PaymentAttempt => Checkout::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::query()->findOrFail($attempt->id));
    $revisited = array_filter($old, static fn (PaymentAttempt $attempt): bool => $fresh($attempt)->reconciled_at?->greaterThan($visited) === true);

    expect($fresh($new)->reconciled_at)->not->toBeNull()
        ->and($fake->callsTo('retrievePayment'))->toContain('retrievePayment:'.$new->provider_payment_id)
        ->and($revisited)->toHaveCount(2)
        ->and($fresh($waiting)->reconciled_at)->toBeNull();
});
