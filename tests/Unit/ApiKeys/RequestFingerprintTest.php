<?php

declare(strict_types=1);

use App\Modules\ApiKeys\Services\RequestFingerprint;

/**
 * The normalized body fingerprint of idempotent requests (plan 7.8).
 */
it('treats bodies that mean the same as the same request', function (string $a, string $b): void {
    expect((new RequestFingerprint)->of($a))->toBe((new RequestFingerprint)->of($b));
})->with([
    'empty and {}' => ['', '{}'],
    'empty and []' => ['', '[]'],
    'whitespace and {}' => ["  \n", '{}'],
    'key order and spacing' => ['{"b":1,"a":{"y":2,"x":1}}', '{ "a": {"x":1, "y":2}, "b": 1 }'],
    'escaped slash' => ['{"url":"https:\/\/x"}', '{"url":"https://x"}'],
]);

it('tells apart bodies that mean something different', function (string $a, string $b): void {
    expect((new RequestFingerprint)->of($a))->not->toBe((new RequestFingerprint)->of($b));
})->with([
    'object vs list' => ['{"metadata":{"0":"a"}}', '{"metadata":["a"]}'],
    'string vs number' => ['{"amount":"10"}', '{"amount":10}'],
    'list order' => ['{"x":[1,2]}', '{"x":[2,1]}'],
    'integer vs float' => ['{"x":1}', '{"x":1.0}'],
]);

it('hashes a body that is not JSON as sent', function (): void {
    expect((new RequestFingerprint)->of('amount=10'))->toBe(hash('sha256', 'amount=10'));
});
