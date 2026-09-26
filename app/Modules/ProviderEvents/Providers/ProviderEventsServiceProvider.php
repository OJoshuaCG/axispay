<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Providers;

use App\Modules\ProviderEvents\Console\PurgeProviderEventsCommand;
use Illuminate\Support\ServiceProvider;

final class ProviderEventsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeProviderEventsCommand::class]);
        }
    }
}
