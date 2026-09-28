<?php

declare(strict_types=1);

use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Jobs\CloseAttemptOfClosedLinkJob;
use App\Modules\Payments\Jobs\CompleteAuthorizedPaymentJob;
use App\Modules\Payments\Jobs\ReconcilePaymentAttemptsJob;
use App\Modules\ProviderEvents\Jobs\ProcessProviderEventJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ADR-0051 (B1): a job still running when the queue's `retry_after` passes
 * is handed to a second worker, so every queued job must stop before it:
 * its own `$timeout` and the worker's default `--timeout` stay below
 * `retry_after`, and a unique job's lock outlives its run.
 */

/**
 * @return list<class-string>
 */
function queuedJobClasses(): array
{
    $classes = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Modules/*/Jobs/*.php') ?: [] as $file) {
        $class = 'App\\Modules\\'.basename(dirname($file, 2)).'\\Jobs\\'.basename($file, '.php');

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('finds the queued jobs', function (): void {
    expect(count(queuedJobClasses()))->toBeGreaterThanOrEqual(8);
});

it('gives every queued job an explicit timeout below retry_after', function (string $connection): void {
    $retryAfter = config()->integer('queue.connections.'.$connection.'.retry_after');

    foreach (queuedJobClasses() as $class) {
        $defaults = (new ReflectionClass($class))->getDefaultProperties();
        $timeout = $defaults['timeout'] ?? null;
        $timeout = is_int($timeout) ? $timeout : null;

        expect($timeout)->toBeInt($class.' declares no $timeout')
            ->and($timeout)->toBeLessThan($retryAfter, $class.' may outlive retry_after');

        if (is_int($timeout) && is_int($defaults['uniqueFor'] ?? null)) {
            expect($defaults['uniqueFor'])->toBeGreaterThanOrEqual($timeout, $class.' releases its unique lock while running');
        }
    }
})->with(['database', 'redis', 'beanstalkd']);

it('starts the workers with a timeout below retry_after', function (): void {
    $entrypoint = (string) file_get_contents(dirname(__DIR__, 3).'/docker/app/entrypoint.sh');
    preg_match_all('/QUEUE_TIMEOUT:-(\d+)/', $entrypoint, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $default) {
        expect((int) $default)->toBeLessThan(config()->integer('queue.connections.database.retry_after'));
    }
});

it('keeps the gateway time of every job under its budget below its own timeout, with room for one call and the merchant validation', function (string $job): void {
    expect(class_exists($job))->toBeTrue();
    $timeout = class_exists($job) ? ((new ReflectionClass($job))->getDefaultProperties()['timeout'] ?? null) : null;
    expect($timeout)->toBeInt();
    $timeout = is_int($timeout) ? $timeout : 0;
    $budget = CallBudget::jobSeconds($timeout);

    // A call only starts when its worst case fits the budget, so the job's
    // gateway time is at most the budget, which ends before the job is killed.
    expect($budget)->toBeLessThan($timeout)
        ->and($budget)->toBeGreaterThanOrEqual(CallBudget::worstCallSeconds() + config()->integer('axispay.checkout.pre_payment_validation_seconds'));
})->with([
    CompleteAuthorizedPaymentJob::class,
    CloseAttemptOfClosedLinkJob::class,
    ProcessProviderEventJob::class,
    ReconcilePaymentAttemptsJob::class,
]);

it('lets a lease holder make at least one bounded gateway call within the lease', function (): void {
    expect(config()->integer('axispay.checkout.confirmation_lease_seconds') - 5)->toBeGreaterThanOrEqual(CallBudget::worstCallSeconds());
});
