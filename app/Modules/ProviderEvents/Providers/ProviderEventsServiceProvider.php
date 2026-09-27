<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Providers;

use App\Modules\ProviderEvents\Console\PurgeProviderEventsCommand;
use App\Modules\ProviderEvents\Console\RetryProviderEventsCommand;
use App\Modules\ProviderEvents\Console\SweepProviderEventsCommand;
use Illuminate\Support\ServiceProvider;

final class ProviderEventsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeProviderEventsCommand::class, RetryProviderEventsCommand::class, SweepProviderEventsCommand::class]);
        }
    }
}
