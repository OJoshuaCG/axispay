<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withCookie;

/*
 * Regression: the shared panel middleware was registered as Livewire
 * persistent middleware. On every Livewire update, Livewire re-ran
 * EncryptCookies and StartSession on its fake request: the already-decrypted
 * session cookie failed to decrypt, the session store switched to a new,
 * empty session id, and the next Livewire request on the page (a second
 * sign-in attempt, the 2FA set-up) failed the CSRF check with a 419.
 */

it('never re-runs the cookie, session or CSRF middleware on Livewire updates', function (): void {
    $stateful = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ];

    $persistent = array_filter(Livewire::getPersistentMiddleware(), is_string(...));

    expect(array_values(array_intersect($persistent, $stateful)))->toBe([]);
});

it('keeps the same session across consecutive Livewire updates on the sign-in page', function (string $panel): void {
    $cookie = config()->string("axispay.session_cookies.{$panel}");
    $page = get($panel === 'admin' ? adminUrl('/login') : appUrl('/login'))->assertOk();
    $html = (string) $page->getContent();

    $sessionId = (string) $page->getCookie($cookie)?->getValue();
    expect($sessionId)->not->toBe('');

    preg_match('/data-update-uri="([^"]+)"/', $html, $uri);
    preg_match('/wire:snapshot="([^"]+)"/', $html, $snapshot);
    expect($uri)->toHaveKey(1)->and($snapshot)->toHaveKey(1);
    $updateUri = html_entity_decode($uri[1] ?? '', ENT_QUOTES | ENT_HTML5);
    $snapshot = html_entity_decode($snapshot[1] ?? '', ENT_QUOTES | ENT_HTML5);

    // Two requests: the bug only showed from the second one on.
    foreach ([1, 2] as $request) {
        withCookie($cookie, $sessionId);
        $response = postJson($updateUri, [
            '_token' => session()->token(),
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ], ['X-Livewire' => '1']);

        $response->assertOk();
        expect($response->getCookie($cookie)?->getValue())->toBe($sessionId, "Livewire request {$request} switched the session");

        $snapshot = $response->json('components.0.snapshot');
        expect($snapshot)->toBeString();
    }
})->with(['admin', 'app']);
