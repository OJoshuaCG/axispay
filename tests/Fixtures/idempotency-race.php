<?php

declare(strict_types=1);

/*
 * Child process of tests/Feature/Concurrency/IdempotencyRaceTest.php: boots
 * the application against the test database, waits for a shared start time
 * and runs what POST /v1/payment_links runs after authentication: claim the
 * key (IdempotencyStore::begin), create the link (CreatePaymentLink) and
 * store the answer (IdempotencyStore::complete). Prints `proceed`, `replay`
 * or the API error code. Several copies run at once, so the race happens in
 * MariaDB, not in PHP.
 *
 * Start barrier: each child writes `<barrier_dir>/ready-<pid>` once booted and
 * waits for `<barrier_dir>/go`, which the test creates when every child is
 * ready, so all of them reach the database at the same moment however long
 * booting took.
 *
 * Usage: php idempotency-race.php <tenant_id> <api_key_id> <key> <barrier_dir>
 */

use App\Modules\ApiKeys\Data\IdempotentRequest;
use App\Modules\ApiKeys\Services\IdempotencyStore;
use App\Modules\ApiKeys\Services\RequestFingerprint;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\PaymentLinks\Actions\CreatePaymentLink;
use App\Modules\PaymentLinks\Data\CreationContext;
use App\Modules\PaymentLinks\Enums\CreatedVia;
use App\Modules\PaymentLinks\Http\Presenters\PaymentLinkPresenter;
use App\Modules\PaymentLinks\Services\PaymentLinkInputParser;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
assert($app instanceof Application);
$app->make(Kernel::class)->bootstrap();

$args = $_SERVER['argv'] ?? [];
$args = is_array($args) ? array_values($args) : [];
$arg = static fn (int $index): string => is_string($args[$index] ?? null) ? $args[$index] : '';
[$tenantId, $apiKeyId, $key, $barrier] = [$arg(1), $arg(2), $arg(3), $arg(4)];

$body = '{"amount":"150.00","currency":"MXN","description":"Race order"}';

file_put_contents($barrier.'/ready-'.getmypid(), '1');
$deadline = microtime(true) + 30;

while (! is_file($barrier.'/go')) {
    if (microtime(true) > $deadline) {
        echo 'barrier-timeout';

        exit(1);
    }

    usleep(200);
}

try {
    echo app(TenantContext::class)->runAsTenant($tenantId, false, static function () use ($apiKeyId, $key, $body): string {
        $hash = app(RequestFingerprint::class)->of($body);
        $store = app(IdempotencyStore::class);
        $decision = $store->begin(new IdempotentRequest($apiKeyId, $key, 'POST', '/v1/payment_links', $hash));

        if ($decision->replay) {
            return 'replay';
        }

        $input = json_decode($body, true);
        $link = app(CreatePaymentLink::class)->handle(
            app(PaymentLinkInputParser::class)->parse(is_array($input) ? $input : []),
            new CreationContext(CreatedVia::Api, new Actor(ActorType::ApiKey, $apiKeyId), $key, $hash),
        );

        $stored = $store->complete($decision, 201, (string) json_encode(PaymentLinkPresenter::toApi($link)));

        return $stored ? 'proceed' : 'proceed-unstored';
    });
} catch (ApiException $e) {
    echo $e->errorCode->value;
}
