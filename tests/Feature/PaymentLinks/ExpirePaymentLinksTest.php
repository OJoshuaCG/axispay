<?php

declare(strict_types=1);

use App\Modules\PaymentLinks\Actions\ExpirePaymentLink;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Jobs\ExpirePaymentLinksJob;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkLookup;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestHelpers;

/**
 * Expiration (plan 9.1): the job expires active links past `expires_at`, in
 * batches, across tenants and modes, each inside its own tenant context.
 */
it('expires due active links of every tenant and mode, and leaves the rest', function (): void {
    Carbon::setTestNow('2026-09-26 12:00:00');
    [$a, $b] = [ApiTestHelpers::readyTenant(), ApiTestHelpers::readyTenant(livemode: true)];

    $dueA = ApiTestHelpers::link($a, state: static fn ($f) => $f->state(['expires_at' => now()->subSecond()]));
    $dueB = ApiTestHelpers::link($b, livemode: true, state: static fn ($f) => $f->state(['expires_at' => now()]));
    $future = ApiTestHelpers::link($a, state: static fn ($f) => $f->state(['expires_at' => now()->addMinute()]));
    $canceled = ApiTestHelpers::link($a, state: static fn ($f) => $f->canceled()->state(['expires_at' => now()->subDay()]));
    $processing = ApiTestHelpers::link($a, state: static fn ($f) => $f->processing()->state(['expires_at' => now()->subDay()]));

    (new ExpirePaymentLinksJob)->handle(app(PaymentLinkLookup::class), app(TenantContext::class), app(ExpirePaymentLink::class));

    expect(ApiTestHelpers::freshLink($dueA->id)->status)->toBe(PaymentLinkStatus::Expired)
        ->and(ApiTestHelpers::freshLink($dueA->id)->expired_at?->toDateTimeString())->toBe('2026-09-26 12:00:00')
        ->and(ApiTestHelpers::freshLink($dueB->id)->status)->toBe(PaymentLinkStatus::Expired)
        ->and(ApiTestHelpers::freshLink($future->id)->status)->toBe(PaymentLinkStatus::Active)
        ->and(ApiTestHelpers::freshLink($canceled->id)->status)->toBe(PaymentLinkStatus::Canceled)
        // Plan 9.1: never expires while a payment is in progress.
        ->and(ApiTestHelpers::freshLink($processing->id)->status)->toBe(PaymentLinkStatus::Processing);
});

it('processes several batches in one run', function (): void {
    config(['axispay.links.expire_batch_size' => 2]);
    $tenant = ApiTestHelpers::readyTenant();

    foreach (range(1, 5) as $i) {
        ApiTestHelpers::link($tenant, state: static fn ($f) => $f->pastExpiry());
    }

    dispatch_sync(new ExpirePaymentLinksJob);

    expect(PaymentLink::query()->withoutGlobalScopes()->where('status', 'expired')->count())->toBe(5);
});

it('runs without a tenant context and restores none afterwards', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    ApiTestHelpers::link($tenant, state: static fn ($f) => $f->pastExpiry());
    $context = app(TenantContext::class);
    $context->clear();

    dispatch_sync(new ExpirePaymentLinksJob);

    expect($context->hasTenant())->toBeFalse()
        ->and(PaymentLink::query()->withoutGlobalScopes()->where('status', 'expired')->count())->toBe(1);
});

it('does not expire a link that changed state meanwhile', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->paid()->pastExpiry());

    $expired = app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): bool => app(ExpirePaymentLink::class)->handle($link->id));

    expect($expired)->toBeFalse()->and(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Paid);
});

it('is scheduled every minute without overlapping, on one server', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => $event instanceof CallbackEvent && str_contains((string) $event->description, ExpirePaymentLinksJob::class),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('* * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('skips links that fail and keeps expiring the ones after them', function (): void {
    config(['axispay.links.expire_batch_size' => 2]);
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    $failing = [
        ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => now()->subHours(3)])),
        ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => now()->subHours(2)])),
    ];
    $later = [
        ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => now()->subHour()])),
        ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => now()->subMinute()])),
        ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => now()->subMinute()])),
    ];
    $failingIds = array_map(static fn (PaymentLink $link): string => $link->id, $failing);

    PaymentLink::updating(static function (PaymentLink $link) use ($failingIds): void {
        if (in_array($link->id, $failingIds, true)) {
            throw new RuntimeException('lock wait timeout');
        }
    });
    $log = captureDefaultLog();

    dispatch_sync(new ExpirePaymentLinksJob);

    foreach ($failing as $link) {
        expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Active);
    }

    foreach ($later as $link) {
        expect(ApiTestHelpers::freshLink($link->id)->status)->toBe(PaymentLinkStatus::Expired);
    }

    expect($log->hasWarningThatContains('A payment link could not be expired.'))->toBeTrue();
});

it('pages correctly through many links due in the same second, failing ones included', function (): void {
    config(['axispay.links.expire_batch_size' => 2]);
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    $sameInstant = CarbonImmutable::parse('2026-09-26 11:59:00.500000');
    $links = [];

    foreach (range(1, 7) as $i) {
        $links[] = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => $sameInstant]));
    }

    usort($links, static fn (PaymentLink $a, PaymentLink $b): int => strcmp($a->id, $b->id));
    $failingIds = [$links[0]->id, $links[1]->id, $links[2]->id];

    PaymentLink::updating(static function (PaymentLink $link) use ($failingIds): void {
        if (in_array($link->id, $failingIds, true)) {
            throw new RuntimeException('lock wait timeout');
        }
    });

    dispatch_sync(new ExpirePaymentLinksJob);

    foreach ($links as $link) {
        expect(ApiTestHelpers::freshLink($link->id)->status)
            ->toBe(in_array($link->id, $failingIds, true) ? PaymentLinkStatus::Active : PaymentLinkStatus::Expired);
    }
});

it('stops starting new batches once its time budget is spent', function (): void {
    config(['axispay.links.expire_batch_size' => 2, 'axispay.links.expire_time_budget_seconds' => 0]);
    $tenant = ApiTestHelpers::readyTenant();

    foreach (range(1, 5) as $i) {
        ApiTestHelpers::link($tenant, state: static fn ($f) => $f->pastExpiry());
    }

    dispatch_sync(new ExpirePaymentLinksJob);
    expect(PaymentLink::query()->withoutGlobalScopes()->where('status', 'expired')->count())->toBe(2);

    // The next run continues where the previous one stopped.
    config(['axispay.links.expire_time_budget_seconds' => 40]);
    dispatch_sync(new ExpirePaymentLinksJob);
    expect(PaymentLink::query()->withoutGlobalScopes()->where('status', 'expired')->count())->toBe(5);
});

it('ends before the worker timeout and stays unique for one schedule period', function (): void {
    $job = new ExpirePaymentLinksJob;

    expect($job->timeout)->toBeLessThan(60)
        ->and($job->uniqueFor)->toBe(60)
        ->and(config('axispay.links.expire_time_budget_seconds'))->toBeLessThan($job->timeout);
});

it('locks each link row before expiring it', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    ApiTestHelpers::link($tenant, state: static fn ($f) => $f->pastExpiry());
    DB::enableQueryLog();

    dispatch_sync(new ExpirePaymentLinksJob);

    expect(ApiTestHelpers::lockedBeforeUpdate(DB::getQueryLog(), 'payment_links'))->toBeTrue();
});

it('runs at most 10 batches per run, and leaves links that become due meanwhile for the next run', function (): void {
    config(['axispay.links.expire_batch_size' => 1, 'axispay.links.expire_time_budget_seconds' => 40]);
    Carbon::setTestNow('2026-09-26 12:00:00');
    $tenant = ApiTestHelpers::readyTenant();

    foreach (range(1, 12) as $i) {
        ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => now()->subMinutes($i)]));
    }

    $later = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => now()->addMinutes(30)]));

    // The clock moves past the later link's expiry while the run is going.
    $moved = false;
    PaymentLink::updating(static function () use (&$moved): void {
        if (! $moved) {
            $moved = true;
            Carbon::setTestNow('2026-09-26 13:00:00');
        }
    });

    dispatch_sync(new ExpirePaymentLinksJob);

    expect(PaymentLink::query()->withoutGlobalScopes()->where('status', 'expired')->count())->toBe(10)
        ->and(ApiTestHelpers::freshLink($later->id)->status)->toBe(PaymentLinkStatus::Active);

    dispatch_sync(new ExpirePaymentLinksJob);

    expect(PaymentLink::query()->withoutGlobalScopes()->where('status', 'expired')->count())->toBe(13);
});
