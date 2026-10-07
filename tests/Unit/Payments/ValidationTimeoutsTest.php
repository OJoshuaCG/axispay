<?php

declare(strict_types=1);

use App\Modules\Payments\Data\CallBudget;
use App\Modules\Payments\Data\ValidationTimeouts;
use App\Modules\Payments\Jobs\CloseAttemptOfClosedLinkJob;
use App\Modules\Payments\Jobs\CompleteAuthorizedPaymentJob;
use App\Modules\Payments\Jobs\ReconcilePaymentAttemptsJob;
use App\Modules\ProviderEvents\Jobs\ProcessProviderEventJob;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;

/**
 * ADR-0061: the merchant validation timeout T (`AXISPAY_VALIDATION_TIMEOUT_SECONDS`)
 * is the only number an operator sets; every dependent time (checkout budget
 * and lease, job timeouts, queue `retry_after`, unique locks, web server
 * limits) is derived from it. T = 5 must give exactly the values the platform
 * had before the setting existed.
 */
it('reproduces the values the platform had before the setting at T = 5', function (): void {
    $timeouts = ValidationTimeouts::fromSeconds(5);

    expect($timeouts->validationSeconds)->toBe(5)
        ->and($timeouts->connectTimeoutSeconds())->toBe(2)
        ->and($timeouts->requestBudgetSeconds())->toBe(50)
        ->and($timeouts->confirmationLeaseSeconds())->toBe(90)
        ->and($timeouts->jobTimeoutSeconds())->toBe(115)
        ->and($timeouts->queueRetryAfterSeconds())->toBe(150)
        ->and($timeouts->workerTimeoutSeconds())->toBe(120)
        ->and($timeouts->completionUniqueForSeconds())->toBe(600)
        ->and($timeouts->providerEventUniqueForSeconds())->toBe(1500)
        ->and($timeouts->nginxReadTimeoutSeconds())->toBe(60)
        ->and($timeouts->fpmTerminateTimeoutSeconds())->toBe(65)
        ->and($timeouts->phpMaxExecutionSeconds())->toBe(30);
});

it('derives the values of the default T = 30', function (): void {
    $timeouts = ValidationTimeouts::fromSeconds(ValidationTimeouts::DEFAULT_SECONDS);

    expect($timeouts->validationSeconds)->toBe(30)
        ->and($timeouts->connectTimeoutSeconds())->toBe(2)
        ->and($timeouts->requestBudgetSeconds())->toBe(75)
        ->and($timeouts->confirmationLeaseSeconds())->toBe(115)
        ->and($timeouts->jobTimeoutSeconds())->toBe(140)
        ->and($timeouts->queueRetryAfterSeconds())->toBe(175)
        ->and($timeouts->workerTimeoutSeconds())->toBe(145)
        ->and($timeouts->completionUniqueForSeconds())->toBe(675)
        ->and($timeouts->providerEventUniqueForSeconds())->toBe(1625)
        ->and($timeouts->nginxReadTimeoutSeconds())->toBe(85)
        ->and($timeouts->fpmTerminateTimeoutSeconds())->toBe(90)
        ->and($timeouts->phpMaxExecutionSeconds())->toBe(55);
});

it('keeps every dependent time in order for every allowed T', function (int $seconds): void {
    $timeouts = ValidationTimeouts::fromSeconds($seconds);
    $worstStripeCall = CallBudget::worstCallSeconds();

    // A payer request fits the merchant validation and one bounded gateway call.
    expect($timeouts->requestBudgetSeconds())->toBeGreaterThanOrEqual($seconds + $worstStripeCall)
        // The lease outlives the request budget with the lease margin to spare.
        ->and($timeouts->confirmationLeaseSeconds() - 5)->toBeGreaterThanOrEqual($timeouts->requestBudgetSeconds())
        // A job keeps room for the validation and one call after its margin.
        ->and(CallBudget::jobSeconds($timeouts->jobTimeoutSeconds()))->toBeGreaterThanOrEqual($seconds + $worstStripeCall)
        // A job is never handed to a second worker while it still runs.
        ->and($timeouts->workerTimeoutSeconds())->toBeGreaterThanOrEqual($timeouts->jobTimeoutSeconds())
        ->and($timeouts->workerTimeoutSeconds())->toBeLessThan($timeouts->queueRetryAfterSeconds())
        ->and($timeouts->jobTimeoutSeconds())->toBeLessThan($timeouts->queueRetryAfterSeconds())
        // The unique locks outlive their jobs.
        ->and($timeouts->completionUniqueForSeconds())->toBeGreaterThanOrEqual($timeouts->jobTimeoutSeconds())
        ->and($timeouts->providerEventUniqueForSeconds())->toBeGreaterThanOrEqual($timeouts->jobTimeoutSeconds())
        // The web server gives up after the application's own budget, and PHP-FPM after the web server.
        ->and($timeouts->nginxReadTimeoutSeconds())->toBeGreaterThan($timeouts->requestBudgetSeconds())
        ->and($timeouts->fpmTerminateTimeoutSeconds())->toBeGreaterThan($timeouts->nginxReadTimeoutSeconds());
})->with(range(ValidationTimeouts::MIN_SECONDS, ValidationTimeouts::MAX_SECONDS));

it('derives the shipped configuration from the one setting', function (): void {
    $timeouts = ValidationTimeouts::current();

    expect($timeouts->validationSeconds)->toBe(ValidationTimeouts::DEFAULT_SECONDS)
        ->and(config()->integer('axispay.pre_payment_validation.timeout_seconds'))->toBe($timeouts->validationSeconds)
        ->and(config()->integer('axispay.pre_payment_validation.connect_timeout_seconds'))->toBe($timeouts->connectTimeoutSeconds())
        ->and(config()->integer('axispay.checkout.pre_payment_validation_seconds'))->toBe($timeouts->validationSeconds)
        ->and(config()->integer('axispay.checkout.request_budget_seconds'))->toBe($timeouts->requestBudgetSeconds())
        ->and(config()->integer('axispay.checkout.confirmation_lease_seconds'))->toBe($timeouts->confirmationLeaseSeconds())
        ->and(config()->integer('queue.connections.database.retry_after'))->toBe($timeouts->queueRetryAfterSeconds());
});

it('accepts the shipped configuration at boot', function (): void {
    ValidationTimeouts::assertConfigured();

    expect(true)->toBeTrue();
});

it('refuses to boot with a T outside 5 to 60 seconds', function (mixed $seconds): void {
    config(['axispay.pre_payment_validation.timeout_seconds' => $seconds]);

    expect(fn () => ValidationTimeouts::assertConfigured())
        ->toThrow(RuntimeException::class, 'AXISPAY_VALIDATION_TIMEOUT_SECONDS');
})->with([0, 4, 61, 300, -1, 'thirty', null]);

it('refuses to boot when a derived value no longer follows T', function (string $key): void {
    config([$key => config()->integer($key) - 1]);

    expect(fn () => ValidationTimeouts::assertConfigured())
        ->toThrow(RuntimeException::class, $key);
})->with([
    'axispay.pre_payment_validation.connect_timeout_seconds',
    'axispay.checkout.pre_payment_validation_seconds',
    'axispay.checkout.request_budget_seconds',
    'axispay.checkout.confirmation_lease_seconds',
]);

it('refuses to boot when a queue retry_after is too short for the jobs', function (string $connection): void {
    config(['queue.connections.'.$connection.'.retry_after' => ValidationTimeouts::current()->queueRetryAfterSeconds() - 1]);

    expect(fn () => ValidationTimeouts::assertConfigured())
        ->toThrow(RuntimeException::class, 'queue.connections.'.$connection.'.retry_after');
})->with(['database', 'redis', 'beanstalkd']);

it('gives the jobs that run the merchant validation the derived timeouts', function (): void {
    app(TenantContext::class)->set(Ulid::generate(), false);
    $timeouts = ValidationTimeouts::current();

    $complete = new CompleteAuthorizedPaymentJob(Ulid::generate());
    $event = new ProcessProviderEventJob(Ulid::generate(), Ulid::generate(), false);
    $reconcile = new ReconcilePaymentAttemptsJob;

    expect($complete->timeout)->toBe($timeouts->jobTimeoutSeconds())
        ->and($complete->uniqueFor)->toBe($timeouts->completionUniqueForSeconds())
        ->and($event->timeout)->toBe($timeouts->jobTimeoutSeconds())
        ->and($event->uniqueFor)->toBe($timeouts->providerEventUniqueForSeconds())
        ->and($reconcile->timeout)->toBe($timeouts->jobTimeoutSeconds());
});

it('follows a changed T when a job is created', function (): void {
    app(TenantContext::class)->set(Ulid::generate(), false);
    config(['axispay.pre_payment_validation.timeout_seconds' => 5]);

    $complete = new CompleteAuthorizedPaymentJob(Ulid::generate());

    expect($complete->timeout)->toBe(115)
        ->and($complete->uniqueFor)->toBe(600);
});

it('leaves the jobs that never call the merchant at their own fixed timeout', function (): void {
    app(TenantContext::class)->set(Ulid::generate(), false);

    // Closing a link's payment only voids it: no validation, so no dependence on T.
    expect((new CloseAttemptOfClosedLinkJob(Ulid::generate()))->timeout)->toBe(115);
});

it('prints the derived values for the container entrypoint', function (): void {
    $timeouts = ValidationTimeouts::current();

    artisanCommand('axispay:validation-timeouts')
        ->expectsOutputToContain('AXISPAY_JOB_TIMEOUT_SECONDS='.$timeouts->jobTimeoutSeconds())
        ->expectsOutputToContain('AXISPAY_QUEUE_RETRY_AFTER_SECONDS='.$timeouts->queueRetryAfterSeconds())
        ->expectsOutputToContain('AXISPAY_QUEUE_TIMEOUT_DEFAULT='.$timeouts->workerTimeoutSeconds())
        ->expectsOutputToContain('AXISPAY_NGINX_FASTCGI_READ_TIMEOUT='.$timeouts->nginxReadTimeoutSeconds())
        ->expectsOutputToContain('AXISPAY_PHP_FPM_REQUEST_TERMINATE_TIMEOUT='.$timeouts->fpmTerminateTimeoutSeconds())
        ->expectsOutputToContain('AXISPAY_PHP_MAX_EXECUTION_TIME='.$timeouts->phpMaxExecutionSeconds())
        ->assertSuccessful();
});

it('fails the entrypoint command when the configuration is inconsistent', function (): void {
    config(['axispay.checkout.request_budget_seconds' => 1]);

    expect(Artisan::call('axispay:validation-timeouts'))->toBe(1);
});

it('templates the web server limits from the derived values instead of fixed numbers', function (): void {
    $root = dirname(__DIR__, 3).'/docker/app';
    $nginx = (string) file_get_contents($root.'/nginx.conf');
    $fpm = (string) file_get_contents($root.'/php-fpm.conf');
    $ini = (string) file_get_contents($root.'/php.ini');
    $entrypoint = (string) file_get_contents($root.'/entrypoint.sh');

    expect($nginx)->not->toMatch('/fastcgi_read_timeout\s+\d/')
        ->and($nginx)->toContain('include /tmp/axispay-timeouts.conf;')
        ->and($fpm)->toContain('request_terminate_timeout = ${AXISPAY_PHP_FPM_REQUEST_TERMINATE_TIMEOUT}s')
        ->and($ini)->toContain('max_execution_time = ${AXISPAY_PHP_MAX_EXECUTION_TIME}')
        ->and($entrypoint)->toContain('axispay:validation-timeouts')
        ->and($entrypoint)->toContain('fastcgi_read_timeout ${AXISPAY_NGINX_FASTCGI_READ_TIMEOUT}s;')
        ->and($entrypoint)->not->toMatch('/QUEUE_TIMEOUT:-\d/');
});
