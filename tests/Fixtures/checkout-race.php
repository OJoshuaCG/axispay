<?php

declare(strict_types=1);

/*
 * Child process of tests/Feature/Concurrency/CheckoutRaceTest.php (plan 26.2
 * case 1): boots the application with the checkout sandbox on, waits for a
 * shared start barrier, then presses "Pay" on the same link, as a second tab
 * or device would. Prints the outcome (`paid`, `in_progress`...). Several
 * copies run at once, so the race happens in MariaDB, not in PHP.
 *
 * Usage: php checkout-race.php <public_token> <barrier_dir>
 */

use App\Modules\Checkout\Actions\StartCheckoutPayment;
use App\Modules\Checkout\Data\CheckoutPaymentInput;
use App\Modules\Checkout\Services\CheckoutLinkResolver;
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

$result = app(StartCheckoutPayment::class)->handle($link, new CheckoutPaymentInput(
    confirmationToken: 'ctoken_sandbox_success_'.getmypid(),
    payer: ['email' => 'race@example.com'],
    turnstileToken: null,
    clientIp: '10.0.0.'.(getmypid() % 250),
    userAgent: 'race',
    sessionDeclines: 0,
));

echo $result->outcome->value;
