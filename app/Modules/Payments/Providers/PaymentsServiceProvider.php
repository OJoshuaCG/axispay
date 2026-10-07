<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\PaymentLinks\Events\PaymentLinkClosed;
use App\Modules\Payments\Console\ReconcilePaymentsCommand;
use App\Modules\Payments\Console\ValidationTimeoutsCommand;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\ValidationTimeouts;
use App\Modules\Payments\Listeners\CloseAttemptOfClosedLink;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Policies\PaymentAttemptPolicy;
use App\Modules\Webhooks\Services\HttpPrePaymentValidator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ADR-0050 step 4 (plan 15.8): the merchant's signed callback. Links
        // created without validation skip it (NoPrePaymentValidation).
        $this->app->bind(PrePaymentValidator::class, HttpPrePaymentValidator::class);
    }

    public function boot(): void
    {
        // ADR-0061: one validation timeout, every dependent limit derived from it.
        ValidationTimeouts::assertConfigured();

        Gate::policy(PaymentAttempt::class, PaymentAttemptPolicy::class);

        // Plan 9.1: a closed link cancels its waiting gateway payment.
        Event::listen(PaymentLinkClosed::class, CloseAttemptOfClosedLink::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcilePaymentsCommand::class, ValidationTimeoutsCommand::class]);
        }
    }
}
