<?php

declare(strict_types=1);

use App\Modules\Shared\Observability\SentryEventScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;

/**
 * Plan section 24.2: the error tracker receives no secrets or PII.
 */
it('scrubs secrets and PII from every part of a Sentry event', function (): void {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://api.localhost/v1/payment_links',
        'headers' => ['Authorization' => 'Bearer plk_live_abc', 'Accept' => 'application/json'],
        'cookies' => ['session' => 'abc'],
        'data' => ['amount' => '10.00', 'payer' => ['email' => 'jane@example.com']],
    ]);
    $event->setExtra(['stripe_key' => 'rk_live_abc', 'note' => 'mail jane@example.com']);
    $event->setTags(['tenant' => '01J8Z3Q6T4Y0V8KX2M1N5P7R9S', 'api_key' => 'plk_test_x']);
    $event->setContext('runtime', ['name' => 'php', 'version' => '8.5.0']);
    $event->setContext('payer', ['name' => 'Jane Doe']);
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('Card 4242424242424242 with sk_live_abc'))]);
    $event->setBreadcrumb([
        new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Paid by jane@example.com', ['password' => 'x', 'status' => 'ok']),
    ]);

    $scrubbed = SentryEventScrubber::beforeSend($event);

    $request = $scrubbed->getRequest();
    expect($request['headers'])->toBe(['Authorization' => '[REDACTED]', 'Accept' => 'application/json'])
        ->and($request['cookies'])->toBe('[REDACTED]')
        ->and($request['data'])->toBe(['amount' => '10.00', 'payer' => '[REDACTED]'])
        ->and($scrubbed->getExtra())->toBe(['stripe_key' => '[REDACTED]', 'note' => 'mail [REDACTED]'])
        ->and($scrubbed->getTags())->toBe(['tenant' => '01J8Z3Q6T4Y0V8KX2M1N5P7R9S', 'api_key' => '[REDACTED]'])
        ->and($scrubbed->getContexts()['runtime'])->toBe(['name' => 'php', 'version' => '8.5.0'])
        ->and($scrubbed->getContexts()['payer'])->toBe(['name' => '[REDACTED]'])
        ->and($scrubbed->getExceptions()[0]->getValue())->toBe('Card [REDACTED] with [REDACTED]')
        ->and($scrubbed->getBreadcrumbs()[0]->getMessage())->toBe('Paid by [REDACTED]')
        ->and($scrubbed->getBreadcrumbs()[0]->getMetadata())->toBe(['password' => '[REDACTED]', 'status' => 'ok']);
});

it('is registered as the before_send hook with PII disabled', function (): void {
    expect(config('sentry.before_send'))->toBe([SentryEventScrubber::class, 'beforeSend'])
        ->and(config('sentry.send_default_pii'))->toBeFalse();
});
