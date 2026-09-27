<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Sandbox\SandboxMode;
use App\Modules\Gateways\Sandbox\SandboxPaymentGateway;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\Gateways\Stripe\StripeGateway;

/**
 * ADR-0051: the checkout sandbox exists only in local and testing; the
 * application refuses to boot with the flag anywhere else.
 */
it('refuses the sandbox flag outside local and testing', function (string $environment): void {
    config(['axispay.checkout.sandbox' => true]);

    expect(fn () => SandboxMode::assertSafe($environment))->toThrow(RuntimeException::class, 'only allowed when APP_ENV is local or testing');
})->with(['production', 'staging', 'prod', '']);

it('accepts the flag in local and testing, and never complains when it is off', function (): void {
    config(['axispay.checkout.sandbox' => true]);
    SandboxMode::assertSafe('local');
    SandboxMode::assertSafe('testing');

    config(['axispay.checkout.sandbox' => false]);
    SandboxMode::assertSafe('production');

    expect(true)->toBeTrue();
});

it('is off in production even if the flag were read', function (): void {
    config(['axispay.checkout.sandbox' => true]);
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        expect(SandboxMode::enabled())->toBeFalse()
            ->and(app(GatewayFactory::class)->for(GatewayProvider::Stripe))->toBeInstanceOf(StripeGateway::class);
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }
});

it('serves the sandbox gateway in testing when enabled', function (): void {
    config(['axispay.checkout.sandbox' => true]);

    expect(SandboxMode::enabled())->toBeTrue()
        ->and(app(GatewayFactory::class)->for(GatewayProvider::Stripe))->toBeInstanceOf(SandboxPaymentGateway::class);
});
