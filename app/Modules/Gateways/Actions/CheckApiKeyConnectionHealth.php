<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Gateways\Enums\HealthCheckStatus;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayFactory;
use Illuminate\Support\Facades\DB;

/**
 * Daily health check of an api_key connection (plan 12.3.3): GET /v1/account
 * with the stored key. Success syncs the account (country, charges enabled)
 * and can heal `invalid_credentials`; an authentication or permission error
 * marks `invalid_credentials` (plan 26.2 case 21); an outage only records
 * the check.
 */
final readonly class CheckApiKeyConnectionHealth
{
    public function __construct(
        private GatewayFactory $gateways,
        private SyncGatewayConnection $sync,
        private MarkConnectionCredentialsInvalid $markInvalid,
    ) {}

    public function handle(GatewayConnection $connection): HealthCheckStatus
    {
        if (! $connection->isApiKey() || $connection->status->isDisconnected()) {
            return HealthCheckStatus::Ok;
        }

        // The key this call uses, as loaded: compared under the lock if it fails.
        $fingerprint = $connection->credentials_fingerprint;

        try {
            $account = $this->gateways->for($connection->provider)->retrieveAccount($connection);
        } catch (GatewayAuthenticationException) {
            $this->markInvalid->handle($connection, $fingerprint);

            return HealthCheckStatus::AuthenticationFailed;
        } catch (GatewayUnavailableException) {
            DB::transaction(static function () use ($connection): void {
                GatewayConnection::query()->lockForUpdate()->findOrFail($connection->id)
                    ->forceFill(['last_health_check_at' => now(), 'last_health_check_status' => HealthCheckStatus::Unavailable])
                    ->save();
            });

            return HealthCheckStatus::Unavailable;
        }

        $this->sync->apply($connection, $account, HealthCheckStatus::Ok);

        return HealthCheckStatus::Ok;
    }
}
