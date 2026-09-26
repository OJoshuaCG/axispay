<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Closure;
use Database\Factories\GatewayConnectionFactory;

/**
 * Shared helpers of the gateway tests.
 */
final class GatewayTestHelpers
{
    /**
     * @param  (Closure(GatewayConnectionFactory): GatewayConnectionFactory)|null  $state
     */
    public static function connection(Tenant $tenant, bool $livemode = false, ?Closure $state = null): GatewayConnection
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, $livemode, static function () use ($state): GatewayConnection {
            $factory = GatewayConnection::factory();

            return ($state !== null ? $state($factory) : $factory)->createOne();
        });
    }

    /** Opens the 10-minute re-authentication window of the current session. */
    public static function reauthenticated(): void
    {
        app(ReauthenticationWindow::class)->confirm();
    }

    /** A valid-looking restricted key of the given mode. */
    public static function restrictedKey(bool $livemode = false, string $suffix = 'A1b2'): string
    {
        return 'rk_'.($livemode ? 'live' : 'test').'_51FakeRestrictedKey0000000000'.$suffix;
    }

    public static function publishableKey(bool $livemode = false, string $suffix = 'Pk01'): string
    {
        return 'pk_'.($livemode ? 'live' : 'test').'_51FakePublishableKey000000000'.$suffix;
    }
}
