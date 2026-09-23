<?php

declare(strict_types=1);

namespace App\Modules\Shared\Providers;

use App\Modules\Shared\Database\SchemaMacros;
use App\Modules\Shared\Http\Errors\ApiErrorRenderer;
use App\Modules\Shared\Logging\Redactor;
use App\Modules\Shared\Money\AmountParser;
use App\Modules\Shared\Money\CurrencyLimits;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the cross-cutting Shared module (money, IDs, API errors, request IDs,
 * log redaction).
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrencyLimits::class);
        $this->app->singleton(AmountParser::class);
        $this->app->singleton(Redactor::class);
        $this->app->singleton(ApiErrorRenderer::class);
    }

    public function boot(): void
    {
        SchemaMacros::register();
    }
}
