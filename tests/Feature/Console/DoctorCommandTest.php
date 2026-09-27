<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\GatewayTestHelpers;

use function Pest\Laravel\travelTo;

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

/*
| Stripe webhooks (ADR-0050): incoming webhooks are mandatory. The Connect
| signing secret must be set while a mode is in use; connections that can
| charge but receive no event are flagged; the last event is shown per mode.
*/

function doctorStripeEvent(GatewayConnection $connection, CarbonImmutable $receivedAt): void
{
    app(TenantContext::class)->runAsTenant($connection->tenant_id, $connection->livemode, static function () use ($connection, $receivedAt): void {
        $row = new ProviderEvent;
        $row->forceFill([
            'provider' => GatewayProvider::Stripe,
            'provider_event_id' => 'evt_'.Ulid::generate(),
            'provider_account_id' => $connection->provider_account_id,
            'livemode' => $connection->livemode,
            'type' => 'account.updated',
            'payload' => '{}',
            'gateway_connection_id' => $connection->id,
            'status' => ProviderEventStatus::Processed,
            'received_at' => $receivedAt,
            'processed_at' => $receivedAt,
        ])->save();
    });
}

function doctorConnection(bool $livemode = false, int $connectedDaysAgo = 10, bool $apiKey = false): GatewayConnection
{
    return GatewayTestHelpers::connection(activeTenant(), $livemode, static function ($factory) use ($apiKey, $connectedDaysAgo) {
        $factory = $apiKey ? $factory->apiKey() : $factory;

        return $factory->state(['connected_at' => now()->subDays($connectedDaysAgo)]);
    });
}

it('fails when a Connect connection exists in a mode whose Connect signing secret is empty', function (): void {
    config(['services.stripe.test.connect_webhook_secret' => '']);
    doctorConnection();

    expect(Artisan::call('axispay:doctor'))->toBe(1);
    expect(Artisan::output())
        ->toContain('Stripe Connect webhook (test)')
        ->toContain('STRIPE_TEST_CONNECT_WEBHOOK_SECRET is empty but the mode is in use (Connect connections exist)')
        ->toContain('/webhooks/stripe/connect/test');
});

it('fails when platform onboarding is enabled with a platform key but the Connect signing secret is missing, without printing the key', function (): void {
    config([
        'axispay.gateways.stripe.connection_methods.platform_onboarding' => true,
        'services.stripe.test.secret' => 'sk_test_doctorplatformkey123',
        'services.stripe.test.connect_webhook_secret' => null,
    ]);

    expect(Artisan::call('axispay:doctor'))->toBe(1);

    $output = Artisan::output();

    expect($output)->toContain('a Connect method is enabled with a platform secret key')
        ->and($output)->not->toContain('sk_test_doctorplatformkey123');
});

it('fails on a Connect signing secret that is not a whsec_ secret, without printing it', function (): void {
    config(['services.stripe.live.connect_webhook_secret' => 'not-a-signing-secret-value']);

    expect(Artisan::call('axispay:doctor'))->toBe(1);

    $output = Artisan::output();

    expect($output)->toContain('STRIPE_LIVE_CONNECT_WEBHOOK_SECRET is not a webhook signing secret')
        ->and($output)->not->toContain('not-a-signing-secret-value');
});

it('does not require the Connect signing secret of a mode that is not in use', function (): void {
    config([
        'services.stripe.live.secret' => '',
        'services.stripe.live.connect_webhook_secret' => '',
    ]);

    expect(Artisan::call('axispay:doctor'))->toBe(0);
    expect(Artisan::output())->toContain('mode not in use');
});

it('never prints the Connect signing secrets or the platform keys', function (): void {
    config(['services.stripe.test.secret' => 'sk_test_doctorplatformkey456']);
    doctorConnection();
    doctorConnection(livemode: true);

    expect(Artisan::call('axispay:doctor'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('signing secret set')
        ->and($output)->not->toContain(config()->string('services.stripe.test.connect_webhook_secret'))
        ->and($output)->not->toContain(config()->string('services.stripe.live.connect_webhook_secret'))
        ->and($output)->not->toContain(config()->string('services.stripe.live.secret'))
        ->and($output)->not->toContain('sk_test_doctorplatformkey456');
});

it('prints the pinned API version and the events of the Connect destination', function (): void {
    expect(Artisan::call('axispay:doctor'))->toBe(0);
    expect(Artisan::output())
        ->toContain('API version '.config()->string('services.stripe.api_version'))
        ->toContain('account.updated, account.application.deauthorized');
});

it('shows when the last Stripe event of each mode was received', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-27 12:00:00', 'UTC'));
    doctorStripeEvent(doctorConnection(), CarbonImmutable::parse('2026-09-25 08:30:00', 'UTC'));

    Artisan::call('axispay:doctor');

    expect(Artisan::output())
        ->toContain('Last Stripe event (test)')
        ->toContain('2026-09-25 08:30:00 UTC')
        ->toMatch('/Last Stripe event \(live\)\s*\|\s*INFO\s*\|\s*none received yet/');
});

it('warns without failing when Connect connections can charge but no Connect event arrived in the window', function (): void {
    doctorStripeEvent(doctorConnection(connectedDaysAgo: 30), now()->toImmutable()->subDays(8));

    expect(Artisan::call('axispay:doctor'))->toBe(0);
    expect(Artisan::output())
        ->toContain('Stripe Connect events (test)')
        ->toContain('1 Connect connection(s) can charge but no event reached')
        ->toContain('in the last 7 days');
});

it('does not warn when a Connect event arrived in the window', function (): void {
    doctorStripeEvent(doctorConnection(connectedDaysAgo: 30), now()->toImmutable()->subDay());

    Artisan::call('axispay:doctor');

    $output = Artisan::output();

    expect($output)->toContain('received in the last 7 days')
        ->and($output)->not->toContain('can charge but');
});

it('reads the silence window from the configuration', function (): void {
    config(['axispay.gateways.stripe.provider_events.silence_warning_days' => 30]);
    doctorStripeEvent(doctorConnection(connectedDaysAgo: 60), now()->toImmutable()->subDays(10));

    Artisan::call('axispay:doctor');

    $output = Artisan::output();

    expect($output)->toContain('received in the last 30 days')
        ->and($output)->not->toContain('can charge but');
});

it('warns about api_key connections that can charge but received no event in the window', function (): void {
    doctorConnection(apiKey: true);

    expect(Artisan::call('axispay:doctor'))->toBe(0);
    expect(Artisan::output())
        ->toContain('Stripe api_key events (test)')
        ->toContain('1 api_key connection(s) can charge but received no event in the last 7 days');
});

it('does not warn about an api_key connection connected inside the window', function (): void {
    doctorConnection(connectedDaysAgo: 2, apiKey: true);

    Artisan::call('axispay:doctor');

    expect(Artisan::output())->not->toContain('api_key connection(s) can charge');
});

it('does not warn about an api_key connection with an event in the window', function (): void {
    doctorStripeEvent(doctorConnection(connectedDaysAgo: 30, apiKey: true), now()->toImmutable()->subDays(3));

    Artisan::call('axispay:doctor');

    expect(Artisan::output())->not->toContain('api_key connection(s) can charge');
});

it('does not warn about disconnected connections', function (): void {
    GatewayTestHelpers::connection(activeTenant(), state: static fn ($factory) => $factory->disconnected()->state(['connected_at' => now()->subDays(30)]));

    Artisan::call('axispay:doctor');

    $output = Artisan::output();

    expect($output)->not->toContain('Stripe Connect events (test)')
        ->and($output)->not->toContain('can charge but');
});
