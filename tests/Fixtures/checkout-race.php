<?php

declare(strict_types=1);

/*
 * Child process of tests/Feature/Concurrency/CheckoutRaceTest.php (plan 26.2
 * case 1): boots the application with the checkout sandbox on, waits for a
 * shared start barrier, then presses "Pay" on the same link, as a second tab
 * or device would. Prints the outcome (`paid`, `in_progress`...). Several
 * copies run at once, so the race happens in MariaDB, not in PHP.
 *
 * With `cancel` as third argument the child cancels the link instead
 * (API/panel cancellation racing the payments): prints `canceled` or
 * `not_cancelable`.
 *
 * Usage: php checkout-race.php <public_token> <barrier_dir> [pay|cancel]
 */

use App\Modules\Audit\Data\Actor;
use App\Modules\Checkout\Actions\StartCheckoutPayment;
use App\Modules\Checkout\Data\CheckoutPaymentInput;
use App\Modules\Checkout\Services\CheckoutLinkResolver;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Exceptions\LinkNotCancelableException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
assert($app instanceof Application);
$app->make(Kernel::class)->bootstrap();

$args = $_SERVER['argv'] ?? [];
$args = is_array($args) ? array_values($args) : [];
$token = is_string($args[1] ?? null) ? $args[1] : '';
$barrier = is_string($args[2] ?? null) ? $args[2] : '';
$mode = is_string($args[3] ?? null) ? $args[3] : 'pay';

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

if ($mode === 'cancel') {
    try {
        app(CancelPaymentLink::class)->handle(PaymentLink::query()->findOrFail($link->id), new CancelPaymentLinkData('race'), Actor::system());
        echo 'canceled';
    } catch (LinkNotCancelableException) {
        echo 'not_cancelable';
    }

    exit(0);
}

$result = app(StartCheckoutPayment::class)->handle($link, new CheckoutPaymentInput(
    confirmationToken: 'ctoken_sandbox_success_'.getmypid(),
    payer: ['email' => 'race@example.com'],
    turnstileToken: null,
    clientIp: '10.0.0.'.(getmypid() % 250),
    userAgent: 'race',
    sessionDeclines: 0,
));

echo $result->outcome->value;
