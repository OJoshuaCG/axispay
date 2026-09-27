<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\PaymentLinks\Events\PaymentLinkClosed;
use App\Modules\Payments\Console\ReconcilePaymentsCommand;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Listeners\CloseAttemptOfClosedLink;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Policies\PaymentAttemptPolicy;
use App\Modules\Payments\Services\NoPrePaymentValidation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ADR-0050 step 4: Phase 5 replaces this binding with the merchant callback.
        $this->app->bind(PrePaymentValidator::class, NoPrePaymentValidation::class);
    }

    public function boot(): void
    {
        Gate::policy(PaymentAttempt::class, PaymentAttemptPolicy::class);

        // Plan 9.1: a closed link cancels its waiting gateway payment.
        Event::listen(PaymentLinkClosed::class, CloseAttemptOfClosedLink::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePaymentsCommand::class]);
        }
    }
}
