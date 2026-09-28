<?php

declare(strict_types=1);

/*
 * Child process of tests/Feature/Concurrency/CheckoutRaceTest.php (plan 26.2
 * case 1 and ADR-0051): boots the application with the checkout sandbox on
 * (its payments in the shared database cache), waits for a shared start
 * barrier, then runs one actor against the same link or attempt, so the
 * races happen in MariaDB, not in PHP. Prints the outcome.
 *
 * Usage: php checkout-race.php <public_token> <barrier_dir> <mode> [argument]
 *
 *   pay [scenario]          press "Pay" with a sandbox card (success by default;
 *                           decline, funds, threeds, processing)
 *   cancel [delay_ms]       cancel the link (canceled, not_cancelable)
 *   webhook <attempt_id>    a payment event: re-read and complete (status)
 *   reconcile <attempt_id>  the reconciliation's re-read (status)
 *   capture <attempt_id>    the checkout's completion of an authorization (outcome)
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
$arg = static fn (int $index): string => is_string($args[$index] ?? null) ? $args[$index] : '';
[$token, $barrier, $mode, $extra] = [$arg(1), $arg(2), $arg(3) !== '' ? $arg(3) : 'pay', $arg(4)];

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
        'cancel' => (static function () use ($link, $extra): string {
            usleep(max(0, (int) $extra) * 1000);

            try {
                app(CancelPaymentLink::class)->handle(PaymentLink::query()->findOrFail($link->id), new CancelPaymentLinkData('race'), Actor::system());

                return 'canceled';
            } catch (LinkNotCancelableException) {
                return 'not_cancelable';
            }
        })(),
        'webhook' => app(SyncPaymentAttempt::class)->handle($extra, SyncReason::Webhook)->status->value,
        'reconcile' => app(SyncPaymentAttempt::class)->handle($extra, SyncReason::Reconciliation)->status->value,
        'capture' => app(CaptureAuthorizedPayment::class)->handle($extra)->outcome->value,
        default => app(StartCheckoutPayment::class)->handle($link, new CheckoutPaymentInput(
            confirmationToken: 'ctoken_sandbox_'.($extra !== '' ? $extra : 'success').'_'.getmypid(),
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
