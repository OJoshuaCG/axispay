<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Sandbox;

use RuntimeException;

/**
 * The checkout sandbox (ADR-0051): a fake gateway on the server and a stub of
 * Stripe.js in the browser, so the whole payment page can be exercised
 * without Stripe keys. It exists for local development and automated tests
 * ONLY:
 *
 *  - it is on only when `axispay.checkout.sandbox` is true AND the
 *    application environment is `local` or `testing`;
 *  - the application refuses to boot when the flag is set in any other
 *    environment (assertSafe(), called by GatewaysServiceProvider), so a
 *    misconfigured production server fails loudly instead of accepting fake
 *    payments.
 */
final class SandboxMode
{
    /** @var list<string> */
    public const array ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    public static function requested(): bool
    {
        return config('axispay.checkout.sandbox') === true;
    }

    public static function enabled(): bool
    {
        return self::requested() && app()->environment(self::ALLOWED_ENVIRONMENTS);
    }

    public static function assertSafe(string $environment): void
    {
        if (self::requested() && ! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new RuntimeException('AXISPAY_CHECKOUT_SANDBOX is only allowed when APP_ENV is local or testing.');
        }
    }
}
