<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Gateways\Actions\ConnectWithApiKey;
use App\Modules\Gateways\Actions\DisconnectGatewayConnection;
use App\Modules\Gateways\Data\ApiKeyConnectionData;
use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Gateways\Jobs\CheckApiKeyConnectionsJob;
use App\Modules\Gateways\Stripe\StripeGateway;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Monolog\Formatter\JsonFormatter;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeApiKeyScenario;

use function Pest\Laravel\startSession;

/**
 * Plan 26.2 case 19 / Phase 4B acceptance: the restricted key never appears
 * in logs, exceptions, job payloads, audit metadata or panel responses. The
 * logs are inspected as they are written (real channel, redaction tap, JSON
 * formatter), across the success and failure paths of the api_key flow.
 */
it('never writes the restricted key anywhere', function (): void {
    startSession();
    Notification::fake();

    $handler = captureDefaultLog();

    $secret = GatewayTestHelpers::restrictedKey(suffix: 'Leak');
    $secretBody = substr($secret, strlen('rk_test_'));
    $owner = actingAsTenantUser(tenantUser());
    GatewayTestHelpers::reauthenticated();
    $scenario = (new StripeApiKeyScenario(stripeHttp()))->without('event_read')->install();
    $data = new ApiKeyConnectionData(ApiKeyCredentials::from($secret, GatewayTestHelpers::publishableKey()), riskAcknowledged: true);
    $exceptions = [];

    // 1. Refused key (missing permission): exception reported like any error.
    try {
        app(ConnectWithApiKey::class)->handle($owner, $data);
    } catch (ApiKeyValidationException $e) {
        $exceptions[] = $e;
        report($e);
    }

    // 2. Refused from the panel page: the response and the snapshot.
    $page = submitAction(Livewire::test(StripeConnection::class), 'connectApiKey', [
        'restricted_key' => $secret,
        'publishable_key' => GatewayTestHelpers::publishableKey(),
        'risk_acknowledged' => true,
    ]);
    $responses = [$page->html(), (string) json_encode($page->get('mountedActions'))];

    // 3. Accepted key, then a revoked key seen by the gateway, then a queued
    //    health check and a disconnection whose remote clean-up fails.
    $scenario->with('event_read');
    $connection = app(ConnectWithApiKey::class)->handle($owner, $data);
    $responses[] = Livewire::test(StripeConnection::class)->html();

    stripeHttp()->error('get', '/v1/account', 401);

    try {
        app(StripeGateway::class)->retrieveAccount($connection);
    } catch (GatewayAuthenticationException $e) {
        $exceptions[] = $e;
        report($e);
    }

    $queue = Queue::fake();
    CheckApiKeyConnectionsJob::dispatch($connection->id);
    $payloads = $queue->pushed(CheckApiKeyConnectionsJob::class)->all();

    stripeHttp()->error('delete', '/v1/webhook_endpoints/*', 500, 'api_error');
    app(DisconnectGatewayConnection::class)->handle($owner, $connection);

    $formatter = new JsonFormatter;
    $logs = implode("\n", array_map(static fn ($record): string => $formatter->format($record), $handler->getRecords()));
    $audit = json_encode(AuditLog::query()->get()->map->getAttributes()->all());
    $exceptionText = implode("\n", array_map(static fn (Throwable $e): string => (string) $e.' '.var_export($e->getPrevious() !== null, true), $exceptions));
    $jobs = implode("\n", array_map(static fn (mixed $job): string => serialize($job), $payloads));

    expect($handler->getRecords())->not->toBeEmpty()
        ->and($payloads)->not->toBeEmpty();

    foreach (['logs' => $logs, 'audit' => (string) $audit, 'exceptions' => $exceptionText, 'jobs' => $jobs, 'panel' => implode("\n", $responses)] as $where => $text) {
        // Not toContain(): it is variadic, a message would become a second needle.
        expect(str_contains($text, $secret))->toBeFalse("The restricted key leaked into the {$where}.")
            ->and(str_contains($text, $secretBody))->toBeFalse("Part of the restricted key leaked into the {$where}.");
    }
});
