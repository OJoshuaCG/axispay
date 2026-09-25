<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    // A healthy baseline; each test breaks one thing.
    config([
        'app.debug' => false,
        'app.url' => 'http://app.localhost',
        'session.driver' => 'database',
        'session.secure' => false,
        'trustedproxy.proxies' => '10.0.0.0/8',
    ]);
});

it('reports the per-host session cookies and a non-reversible APP_KEY fingerprint', function (): void {
    $key = config()->string('app.key');

    expect(Artisan::call('axispay:doctor'))->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->toContain('admin.localhost uses axispay_admin_session')
        ->toContain('app.localhost uses axispay_app_session')
        ->toContain(substr(hash('sha256', $key), 0, 12));
    expect(str_contains($output, $key))->toBeFalse();
});

it('fails when APP_URL is http but the session cookie is secure-only', function (): void {
    config(['session.secure' => true]);

    expect(Artisan::call('axispay:doctor'))->toBe(1);
    expect(Artisan::output())->toContain('SESSION_SECURE_COOKIE=true');
});

it('fails when debug mode is on outside local', function (): void {
    config(['app.debug' => true]);

    expect(Artisan::call('axispay:doctor'))->toBe(1);
});

it('warns without failing when every proxy is trusted', function (): void {
    config(['trustedproxy.proxies' => '*']);

    expect(Artisan::call('axispay:doctor'))->toBe(0);
    expect(Artisan::output())->toContain('trusts every client');
});
