<?php

declare(strict_types=1);

use App\Modules\Webhooks\Services\WebhookSigner;

/**
 * Plan 15.5 / ADR-0008: signatures verifiable with any Standard Webhooks
 * library. The vector is the one published with the specification's
 * reference libraries (standard-webhooks, svix).
 */
it('signs the Standard Webhooks reference vector', function (): void {
    $signature = app(WebhookSigner::class)->sign(
        'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
        'msg_p5jXN8AQM9LWM0D4loKWxJek',
        1614265330,
        '{"test": 2432232314}',
    );

    expect($signature)->toBe('v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=');
});

it('signs HMAC-SHA256 of id.timestamp.body with the decoded secret bytes', function (): void {
    $bytes = random_bytes(32);
    $secret = 'whsec_'.base64_encode($bytes);
    $body = '{"id":"evt_01J8Z5Q6T4Y0V8KX2M1N5P7R9S","type":"ping"}';

    expect(app(WebhookSigner::class)->sign($secret, 'evt_01J8Z5Q6T4Y0V8KX2M1N5P7R9S', 1758679870, $body))
        ->toBe('v1,'.base64_encode(hash_hmac('sha256', 'evt_01J8Z5Q6T4Y0V8KX2M1N5P7R9S.1758679870.'.$body, $bytes, true)));
});

it('sends one signature per secret, separated by a space, during a rotation', function (): void {
    $signer = app(WebhookSigner::class);
    [$new, $old] = [$signer->generateSecret(), $signer->generateSecret()];

    $header = $signer->header([$new, $old], 'evt_x', 1758679870, '{}');
    $parts = explode(' ', $header);

    expect($parts)->toHaveCount(2)
        ->and($parts[0])->toBe($signer->sign($new, 'evt_x', 1758679870, '{}'))
        ->and($parts[1])->toBe($signer->sign($old, 'evt_x', 1758679870, '{}'))
        ->and($signer->verify($new, 'evt_x', 1758679870, '{}', $header, 1758679870))->toBeTrue()
        ->and($signer->verify($old, 'evt_x', 1758679870, '{}', $header, 1758679870))->toBeTrue();
});

it('rejects a wrong secret, a changed body and a timestamp outside the tolerance', function (): void {
    $signer = app(WebhookSigner::class);
    $secret = $signer->generateSecret();
    $header = $signer->header([$secret], 'evt_x', 1758679870, '{"a":1}');

    expect($signer->verify($signer->generateSecret(), 'evt_x', 1758679870, '{"a":1}', $header, 1758679870))->toBeFalse()
        ->and($signer->verify($secret, 'evt_x', 1758679870, '{"a":2}', $header, 1758679870))->toBeFalse()
        ->and($signer->verify($secret, 'evt_x', 1758679870, '{"a":1}', $header, 1758679870 + 301))->toBeFalse()
        ->and($signer->verify($secret, 'evt_x', 1758679870, '{"a":1}', $header, 1758679870 + 300))->toBeTrue();
});

it('generates whsec_ secrets of 32 random bytes', function (): void {
    $signer = app(WebhookSigner::class);
    $secret = $signer->generateSecret();

    expect($secret)->toStartWith('whsec_')
        ->and(base64_decode(substr($secret, 6), true))->toBeString()
        ->and(strlen((string) base64_decode(substr($secret, 6), true)))->toBe(32)
        ->and($signer->generateSecret())->not->toBe($secret);
});

it('refuses a secret without the whsec_ prefix or with invalid base64', function (string $secret): void {
    expect(fn () => app(WebhookSigner::class)->sign($secret, 'evt_x', 1, '{}'))->toThrow(InvalidArgumentException::class);
})->with([
    'no prefix' => ['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'],
    'not base64' => ['whsec_***'],
    'empty' => ['whsec_'],
]);
