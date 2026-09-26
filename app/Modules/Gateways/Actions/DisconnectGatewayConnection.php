<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Enums\ConnectionNotice;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\DisconnectReason;
use App\Modules\Gateways\Events\GatewayConnectionDisconnected;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Notifications\GatewayConnectionNotification;
use App\Modules\Gateways\Services\GatewayNotificationRecipients;
use App\Modules\Gateways\Stripe\Connection\ApiKeyFlow;
use App\Modules\Gateways\Stripe\StripeClientFactory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Ends a connection (plan 12.3.4): `disconnected` is terminal; reconnecting
 * (with the same or another method) creates a new connection.
 *
 *  - api_key: the webhook endpoint on the merchant account is deleted while
 *    the key still works (best effort) and every stored credential is
 *    overwritten with NULL; the audit log keeps the event, never the value.
 *  - platform_onboarding: Stripe does not let a platform deauthorize,
 *    reject or delete a live Standard-equivalent account it created
 *    (ADR-0047), so the disconnection is local; the merchant keeps their
 *    Stripe account.
 *
 * Once committed, GatewayConnectionDisconnected lets the PaymentLinks module
 * cancel the active links of that tenant and mode (plan 12.3.4).
 */
final readonly class DisconnectGatewayConnection
{
    public function __construct(
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private StripeClientFactory $clients,
        private ApiKeyFlow $apiKeys,
        private GatewayNotificationRecipients $recipients,
    ) {}

    /** From the panel: `gateway:manage` + re-authentication (plan 17.3). */
    public function handle(User $actor, GatewayConnection $connection): GatewayConnection
    {
        Gate::forUser($actor)->authorize('manage', $connection);
        $this->reauthentication->ensureConfirmed();

        return $this->disconnect($connection, DisconnectReason::UserRequested, Actor::user($actor->id));
    }

    /** The merchant removed the platform from their account (plan 14.3). */
    public function handleDeauthorized(GatewayConnection $connection): GatewayConnection
    {
        return $this->disconnect($connection, DisconnectReason::Deauthorized, Actor::system());
    }

    private function disconnect(GatewayConnection $connection, DisconnectReason $reason, Actor $actor): GatewayConnection
    {
        if ($connection->status->isDisconnected()) {
            return $connection;
        }

        $remoteDeleted = $this->deleteRemoteEndpoint($connection);

        [$updated, $changed] = DB::transaction(function () use ($connection, $reason, $actor, $remoteDeleted): array {
            $locked = GatewayConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if ($locked->status->isDisconnected()) {
                return [$locked, false];
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => ConnectionStatus::Disconnected,
                'disconnected_at' => now(),
                'disconnect_reason' => $reason,
                'credentials_secret' => null,
                'credentials_publishable' => null,
                'credentials_fingerprint' => null,
                'credentials_key_version' => null,
                'provider_webhook_endpoint_id' => null,
                'provider_webhook_secret' => null,
            ])->save();

            $this->audit->record(AuditAction::GatewayDisconnected, $locked, [
                'before' => ['status' => $from->value],
                'after' => ['status' => ConnectionStatus::Disconnected->value],
                'reason' => $reason->value,
                'method' => $locked->connection_method->value,
                'livemode' => $locked->livemode,
                'remote_webhook_endpoint_deleted' => $remoteDeleted,
            ], actor: $actor);

            return [$locked, true];
        });

        if ($changed) {
            // Owners are told first: nothing that follows may keep them
            // from hearing about the disconnection.
            Notification::send($this->recipients->of($updated->tenant_id), new GatewayConnectionNotification(ConnectionNotice::Disconnected, $updated->livemode));
            event(new GatewayConnectionDisconnected($updated->id, $updated->tenant_id, $updated->livemode));
        }

        return $updated;
    }

    private function deleteRemoteEndpoint(GatewayConnection $connection): ?bool
    {
        if (! $connection->isApiKey() || $connection->provider_webhook_endpoint_id === null || $connection->credentials_secret === null) {
            return null;
        }

        try {
            return $this->apiKeys->deleteWebhookEndpoint($this->clients->for($connection), $connection->provider_webhook_endpoint_id);
        } catch (Throwable $e) {
            Log::warning('The remote webhook endpoint could not be deleted on disconnect.', [
                'connection_id' => $connection->id,
                'exception' => $e::class,
            ]);

            return false;
        }
    }
}
