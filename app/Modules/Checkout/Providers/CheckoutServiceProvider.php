<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Providers;

use App\Modules\Checkout\Console\CheckoutDemoCommand;
use App\Modules\Checkout\Listeners\BlockCheckoutAfterDeclines;
use App\Modules\Payments\Events\PaymentDeclined;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CheckoutServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Plan 11.7 rule 4: repeated declines block the link.
        Event::listen(PaymentDeclined::class, BlockCheckoutAfterDeclines::class);

        if ($this->app->runningInConsole()) {
            $this->commands([CheckoutDemoCommand::class]);
        }
    }
}
