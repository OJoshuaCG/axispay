<?php

declare(strict_types=1);

use App\Modules\Shared\Logging\RedactSensitiveLogData;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;

/**
 * The redaction processor is installed on real log channels (plan 23.3, 24.1).
 */
function captureChannel(): TestHandler
{
    config(['logging.channels.capture' => [
        'driver' => 'monolog',
        'handler' => TestHandler::class,
        'tap' => [RedactSensitiveLogData::class],
    ]]);

    $logger = Log::channel('capture');
    $monolog = $logger instanceof Logger ? $logger->getLogger() : null;
    $handler = $monolog instanceof Monolog ? $monolog->getHandlers()[0] : null;

    if (! $handler instanceof TestHandler) {
        throw new LogicException('The capture channel must use a TestHandler.');
    }

    return $handler;
}

it('redacts message, context and shared context of a log record', function (): void {
    $handler = captureChannel();
    Context::add('request_id', 'req_01J8Z3Q6T4Y0V8KX2M1N5P7R9S');
    Context::add('payer_email', 'jane@example.com');

    Log::channel('capture')->warning('Charge for jane@example.com with rk_live_abcdef failed', [
        'api_key' => 'axp_live_4eC39HqLyjWDarjtT1zdp7dc',
        'nested' => ['password' => 'hunter2', 'amount_minor' => 15050],
        'card' => '4242424242424242',
    ]);

    $record = $handler->getRecords()[0];
    $serialized = json_encode([$record->message, $record->context, $record->extra], JSON_THROW_ON_ERROR);

    expect($record->message)->toBe('Charge for [REDACTED] with [REDACTED] failed')
        ->and($record->context['api_key'])->toBe('[REDACTED]')
        ->and($record->context['nested'])->toBe(['password' => '[REDACTED]', 'amount_minor' => 15050])
        ->and($record->extra['request_id'])->toBe('req_01J8Z3Q6T4Y0V8KX2M1N5P7R9S')
        ->and($serialized)->not->toContain('jane@example.com')
        ->and($serialized)->not->toContain('hunter2')
        ->and($serialized)->not->toContain('4242424242424242')
        ->and($serialized)->not->toContain('rk_live_abcdef');
});

it('installs the redaction tap on every channel that writes output', function (string $channel): void {
    expect(config("logging.channels.{$channel}.tap"))->toContain(RedactSensitiveLogData::class);
})->with(['stack', 'single', 'daily', 'monthly', 'json', 'stderr', 'syslog', 'errorlog', 'slack', 'papertrail']);

it('writes structured, redacted JSON lines on the json channel', function (): void {
    $directory = storage_path('framework/testing/logs-'.bin2hex(random_bytes(4)));
    config(['logging.channels.json.handler_with.filename' => $directory.'/app.json.log']);
    Context::add('request_id', 'req_01J8Z3Q6T4Y0V8KX2M1N5P7R9S');

    Log::channel('json')->error('Webhook secret whsec_abc123 rotated', ['token' => 'tok_secret']);

    $files = glob($directory.'/*.log') ?: [];
    expect($files)->toHaveCount(1);

    $line = json_decode(trim((string) file_get_contents($files[0])), true, flags: JSON_THROW_ON_ERROR);

    expect(config('logging.channels.json.formatter'))->toBe(JsonFormatter::class)
        ->and($line)->toBeArray()
        ->and(data_get($line, 'message'))->toBe('Webhook secret [REDACTED] rotated')
        ->and(data_get($line, 'context'))->toBe(['token' => '[REDACTED]'])
        ->and(data_get($line, 'extra.request_id'))->toBe('req_01J8Z3Q6T4Y0V8KX2M1N5P7R9S')
        ->and(data_get($line, 'level_name'))->toBe('ERROR');

    array_map('unlink', $files);
    rmdir($directory);
});
