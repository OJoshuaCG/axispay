<?php

declare(strict_types=1);

/*
 * Child process of tests/Feature/Concurrency/CheckoutRaceTest.php (plan 26.2
 * case 1 and ADR-0051): boots the application with the checkout sandbox on
 * (its payments in the shared database cache), waits for a shared start
 * barrier, then runs one actor against the same link or attempt, so the
 * races happen in MariaDB, not in PHP. Prints the outcome.
 *
 * Usage: php checkout-race.php <barrier_dir> <actor_json> (Tests\Support\RaceHarness)
 *
 * The actor names its link (`token`) and one `mode`:
 *
 *   pay        press "Pay" with a sandbox card: `scenario` (success by
 *              default; decline, funds, threeds, processing)
 *   cancel     cancel the link after `delay_ms` (canceled, not_cancelable)
 *   webhook    a payment event for `attempt`: re-read and complete (status)
 *   reconcile  the reconciliation's re-read of `attempt` (status)
 *   capture    the checkout's completion of `attempt` (outcome)
 */

use App\Modules\Audit\Data\Actor;
use App\Modules\Checkout\Actions\StartCheckoutPayment;
use App\Modules\Checkout\Data\CheckoutPaymentInput;
use App\Modules\Checkout\Services\CheckoutLinkResolver;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Exceptions\LinkNotCancelableException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Exceptions\AttemptBusyException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
assert($app instanceof Application);
$app->make(Kernel::class)->bootstrap();

$args = $_SERVER['argv'] ?? [];
$args = is_array($args) ? array_values($args) : [];
$barrier = is_string($args[1] ?? null) ? $args[1] : '';
$actor = json_decode(is_string($args[2] ?? null) ? $args[2] : '{}', true);
$actor = is_array($actor) ? $actor : [];
$field = static fn (string $name): string => is_scalar($actor[$name] ?? null) ? (string) $actor[$name] : '';
[$token, $mode] = [$field('token'), $field('mode') !== '' ? $field('mode') : 'pay'];

file_put_contents($barrier.'/ready-'.getmypid(), '1');
$deadline = microtime(true) + 30;

while (! is_file($barrier.'/go')) {
    if (microtime(true) > $deadline) {
        echo 'barrier-timeout';

        exit(1);
    }

    usleep(200);
}

$link = app(CheckoutLinkResolver::class)->resolve($token);

if ($link === null) {
    echo 'not-found';

    exit(1);
}

try {
    echo match ($mode) {
        'cancel' => (static function () use ($link, $field): string {
            usleep(max(0, (int) $field('delay_ms')) * 1000);

            try {
                app(CancelPaymentLink::class)->handle(PaymentLink::query()->findOrFail($link->id), new CancelPaymentLinkData('race'), Actor::system());

                return 'canceled';
            } catch (LinkNotCancelableException) {
                return 'not_cancelable';
            }
        })(),
        'webhook' => app(SyncPaymentAttempt::class)->handle($field('attempt'), SyncReason::Webhook)->status->value,
        'reconcile' => app(SyncPaymentAttempt::class)->handle($field('attempt'), SyncReason::Reconciliation)->status->value,
        'capture' => app(CaptureAuthorizedPayment::class)->handle($field('attempt'))->outcome->value,
        default => app(StartCheckoutPayment::class)->handle($link, new CheckoutPaymentInput(
            confirmationToken: 'ctoken_sandbox_'.($field('scenario') !== '' ? $field('scenario') : 'success').'_'.getmypid(),
            payer: ['email' => 'race@example.com'],
            turnstileToken: null,
            clientIp: '10.0.0.'.(getmypid() % 250),
            userAgent: 'race',
            sessionDeclines: 0,
        ))->outcome->value,
    };
} catch (AttemptBusyException) {
    echo 'busy';
}
