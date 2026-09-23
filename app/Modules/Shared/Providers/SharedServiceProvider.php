<?php

declare(strict_types=1);

namespace App\Modules\Shared\Providers;

use App\Modules\Shared\Database\SchemaMacros;
use App\Modules\Shared\Http\Errors\ApiErrorRenderer;
use App\Modules\Shared\Logging\Redactor;
use App\Modules\Shared\Money\AmountParser;
use App\Modules\Shared\Money\CurrencyLimits;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        self::assertHostOnlySessionCookies();
    }

    /**
     * ADR-0034 / plan 4.1: session cookies are host-only, so the admin and app
     * hosts never share one. A `SESSION_DOMAIN` (e.g. `.example.com`) would
     * send the cookie to every subdomain; refuse to boot with it.
     */
    public static function assertHostOnlySessionCookies(): void
    {
        $domain = config('session.domain');

        if ($domain !== null && $domain !== '') {
            throw new RuntimeException('SESSION_DOMAIN must be empty (null): session cookies must stay host-only so the admin and app panels never share a session (ADR-0034).');
        }
    }
}
