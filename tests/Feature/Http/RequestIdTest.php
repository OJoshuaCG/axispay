<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;

/**
 * Plan sections 10.1 and 24.1: every response carries a Request-Id that is
 * also present in the log context.
 */
beforeEach(function (): void {
    Route::get('/_test/request-id', fn () => response()->json(['context' => Context::get('request_id')]));
});

it('generates a req_<ULID> request id when none is supplied', function (): void {
    $response = get('/_test/request-id');

    $id = $response->headers->get('Request-Id');

    expect($id)->toMatch('/^req_[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and($response->json('context'))->toBe($id);
});

it('reuses a safe incoming X-Request-Id', function (string $incoming): void {
    $response = get('/_test/request-id', ['X-Request-Id' => $incoming]);

    expect($response->headers->get('Request-Id'))->toBe($incoming)
        ->and($response->json('context'))->toBe($incoming);
})->with([
    'upstream uuid' => ['3f2c1b9e-8d7a-4c6b-9e5f-1a2b3c4d5e6f'],
    'our own format' => ['req_01J8Z3Q6T4Y0V8KX2M1N5P7R9S'],
    'proxy style' => ['edge:abc.123_def'],
]);

it('replaces an unsafe incoming X-Request-Id', function (string $incoming): void {
    $response = get('/_test/request-id', ['X-Request-Id' => $incoming]);

    expect($response->headers->get('Request-Id'))->not->toBe($incoming)
        ->and($response->headers->get('Request-Id'))->toMatch('/^req_[0-7][0-9A-HJKMNP-TV-Z]{25}$/');
})->with([
    'spaces' => ['abc def'],
    'markup' => ['<script>alert(1)</script>'],
    'json injection' => ['abc","level":"emergency'],
    'too long' => [str_repeat('a', 129)],
    'leading dash' => ['-abc'],
]);

it('adds the request id to error responses too', function (): void {
    $response = get('/_test/does-not-exist');

    $response->assertNotFound();
    expect($response->headers->get('Request-Id'))->toMatch('/^req_/');
});
