<?php

declare(strict_types=1);

use function Pest\Laravel\get;

// ADR-0040: 419 auto-reload and session keep-alive on both panels.

it('answers the keep-alive ping on both panel hosts with an uncached 204', function (string $panel): void {
    $cookie = config()->string("axispay.session_cookies.{$panel}");
    $response = get($panel === 'admin' ? adminUrl('/session/ping') : appUrl('/session/ping'));

    $response->assertNoContent();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    // The ping renews the panel's own session.
    expect($response->getCookie($cookie))->not->toBeNull();
})->with(['admin', 'app']);

it('does not serve the keep-alive ping on the checkout or API hosts', function (string $host): void {
    get('http://'.config()->string("axispay.surfaces.{$host}").'/session/ping')->assertNotFound();
})->with(['pay', 'api']);

it('throttles the keep-alive ping', function (): void {
    foreach (range(1, 30) as $ping) {
        get(adminUrl('/session/ping'))->assertNoContent();
    }

    get(adminUrl('/session/ping'))->assertTooManyRequests();
});

it('injects the 419 reload and keep-alive script into both panels', function (string $panel): void {
    get($panel === 'admin' ? adminUrl('/login') : appUrl('/login'))
        ->assertOk()
        ->assertSee('Livewire.interceptRequest', false)
        ->assertSee('axispay:session-expired-reload', false)
        ->assertSee('"\/session\/ping"', false);
})->with(['admin', 'app']);
