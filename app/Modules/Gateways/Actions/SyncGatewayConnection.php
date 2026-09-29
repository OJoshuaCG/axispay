<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Data\ConnectedAccountData;
use App\Modules\Gateways\Enums\ConnectionNotice;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\HealthCheckStatus;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Notifications\GatewayConnectionNotification;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\Gateways\Services\GatewayNotificationRecipients;
use App\Modules\Gateways\Services\TenantGatewayActivation;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;

/**
 * Re-reads the gateway account and applies its state to the connection (plan
 * 12.3.1 steps 3 and 6, 12.3.4, 14.3 `account.updated`). The gateway is the
 * source of truth, never the redirect or the webhook payload (ADR-017).
 *
 * Status rule: charges enabled (or a test-mode api_key connection, whose
 * charges do not depend on the flag, ADR-0055) -> `active`; otherwise
 * `restricted` once the connection could charge before (or always for
 * api_key, which has no assisted onboarding), else it stays `onboarding`.
 * Stripe's own flags are stored as reported either way. Runs in the tenant
 * context of the connection; the transition happens under a row lock.
 */
final readonly class SyncGatewayConnection
{
    public function __construct(
        private GatewayFactory $gateways,
        private AuditLogger $audit,
        private GatewayNotificationRecipients $recipients,
        private TenantGatewayActivation $activation,
        private MarkConnectionCredentialsInvalid $markInvalid,
    ) {}

    /**
     * @throws GatewayAuthenticationException for Connect methods (the caller
     *                                        decides: a deauthorization event disconnects, anything else alerts)
     */
    public function handle(GatewayConnection $connection): GatewayConnection
    {
        if ($connection->status->isDisconnected() || $connection->provider_account_id === null) {
            return $connection;
        }

        // The key this call uses, as loaded: compared under the lock if it fails.
        $fingerprint = $connection->credentials_fingerprint;

        try {
            $account = $this->gateways->for($connection->provider)->retrieveAccount($connection);
        } catch (GatewayAuthenticationException $e) {
            if ($connection->isApiKey()) {
                // Plan 12.3.3 / 26.2 case 21: a revoked key blocks the connection.
                return $this->markInvalid->handle($connection, $fingerprint);
            }

            throw $e;
        }

        return $this->apply($connection, $account);
    }

    public function apply(GatewayConnection $connection, ConnectedAccountData $account, ?HealthCheckStatus $health = null): GatewayConnection
    {
        [$updated, $from] = DB::transaction(function () use ($connection, $account, $health): array {
            $locked = GatewayConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $from = $locked->status;

            if ($from->isDisconnected()) {
                return [$locked, $from];
            }

            if ($locked->provider_account_id !== $account->providerAccountId) {
                throw new LogicException('The gateway returned a different account than the connection holds.');
            }

            $to = self::statusFor($locked, $account);

            $locked->forceFill([
                'status' => $to,
                'country' => $account->country ?? $locked->country,
                'default_currency' => $account->defaultCurrency ?? $locked->default_currency,
                'charges_enabled' => $account->chargesEnabled,
                'payouts_enabled' => $account->payoutsEnabled,
                'details_submitted' => $account->detailsSubmitted,
                'requirements' => $account->requirements,
                'last_synced_at' => now(),
                'connected_at' => $locked->connected_at ?? ($to === ConnectionStatus::Active ? now() : null),
            ]);

            if ($health !== null) {
                $locked->forceFill(['last_health_check_at' => now(), 'last_health_check_status' => $health]);
            }

            $locked->save();

            if ($from !== $to) {
                $this->audit->record(AuditAction::GatewayStatusChanged, $locked, [
                    'before' => ['status' => $from->value],
                    'after' => ['status' => $to->value],
                    'method' => $locked->connection_method->value,
                    'livemode' => $locked->livemode,
                ]);
            }

            return [$locked, $from];
        });

        $this->notifyTransition($updated, $from, $connection->connected_at === null);
        $this->activation->afterGatewayReady($updated);

        return $updated;
    }

    public static function statusFor(GatewayConnection $connection, ConnectedAccountData $account): ConnectionStatus
    {
        if ($connection->accountAllowsCharges($account->chargesEnabled)) {
            return ConnectionStatus::Active;
        }

        $couldChargeBefore = in_array($connection->status, [ConnectionStatus::Active, ConnectionStatus::Restricted, ConnectionStatus::InvalidCredentials], true)
            || $connection->connected_at !== null;

        return $couldChargeBefore || $connection->isApiKey() ? ConnectionStatus::Restricted : ConnectionStatus::Onboarding;
    }

    private function notifyTransition(GatewayConnection $connection, ConnectionStatus $from, bool $wasNeverConnected): void
    {
        $notice = match (true) {
            $connection->status === ConnectionStatus::Active && $from !== ConnectionStatus::Active && $wasNeverConnected => ConnectionNotice::Connected,
            $connection->status === ConnectionStatus::Restricted && $from !== ConnectionStatus::Restricted => ConnectionNotice::Restricted,
            default => null,
        };

        if ($notice !== null) {
            Notification::send($this->recipients->of($connection->tenant_id), (new GatewayConnectionNotification($notice, $connection->livemode))->locale(app(TenantAccess::class)->defaultLocale($connection->tenant_id)));
        }
    }
}
