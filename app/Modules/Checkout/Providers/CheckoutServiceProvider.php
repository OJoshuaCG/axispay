<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Providers;

use App\Modules\Checkout\Console\CheckoutDemoCommand;
use App\Modules\Checkout\Http\CheckoutRateLimits;
use App\Modules\Checkout\Listeners\BlockCheckoutAfterDeclines;
use App\Modules\Payments\Events\PaymentDeclined;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class CheckoutServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        self::assertTurnstileKeys((string) $this->app->environment());
        CheckoutRateLimits::register();

        // Plan 11.7 rule 4: repeated declines block the link.
        Event::listen(PaymentDeclined::class, BlockCheckoutAfterDeclines::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CheckoutDemoCommand::class]);
        }
    }

    /**
     * ADR-0051: Turnstile is required after the first decline; without its
     * keys in production every link would stop taking payments after one
     * decline. Refuse to boot in production without both keys.
     */
    public static function assertTurnstileKeys(string $environment): void
    {
        if ($environment !== 'production') {
            return;
        }

        foreach (['services.turnstile.site_key' => 'TURNSTILE_SITE_KEY', 'services.turnstile.secret_key' => 'TURNSTILE_SECRET_KEY'] as $key => $name) {
            $value = config($key);

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("{$name} must be set in production: without Turnstile a link stops taking payments after its first decline (ADR-0051).");
            }
        }
    }
}
