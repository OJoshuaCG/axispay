<?php

declare(strict_types=1);

use App\Modules\PlatformAdmin\Actions\StartImpersonation;
use App\Modules\Shared\Providers\SharedServiceProvider;

use function Pest\Laravel\get;

/**
 * M3 / ADR-0034: each panel host has its own session cookie, and cookies are
 * host-only.
 */
it('uses a separate session cookie per panel host', function (string $url, string $cookie, string $other): void {
    $response = get($url)->assertOk();

    $names = array_map(static fn ($c): string => $c->getName(), $response->headers->getCookies());

    expect(in_array($cookie, $names, true))->toBeTrue()
        ->and(in_array($other, $names, true))->toBeFalse();
})->with([
    'admin' => [fn (): string => adminUrl('/login'), 'paylink_admin_session', 'paylink_app_session'],
    'app' => [fn (): string => appUrl('/login'), 'paylink_app_session', 'paylink_admin_session'],
]);

it('refuses to boot with a shared session cookie domain', function (): void {
    config(['session.domain' => '.localhost']);

    SharedServiceProvider::assertHostOnlySessionCookies();
})->throws(RuntimeException::class);

it('only touches the app-host session when an impersonation is consumed', function (): void {
    $started = app(StartImpersonation::class)->handle(platformAdmin(), tenantUser(), 'Ticket');

    $response = get($started->handoffUrl);
    $names = array_map(static fn ($c): string => $c->getName(), $response->headers->getCookies());

    expect(in_array('paylink_app_session', $names, true))->toBeTrue()
        ->and(in_array('paylink_admin_session', $names, true))->toBeFalse();
});
