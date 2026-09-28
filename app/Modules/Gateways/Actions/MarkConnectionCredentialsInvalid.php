<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Enums\ConnectionNotice;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Enums\HealthCheckStatus;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Notifications\GatewayConnectionNotification;
use App\Modules\Gateways\Services\GatewayNotificationRecipients;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Plan 12.3.3 / 12.6 / 26.2 case 21: the gateway rejected an api_key
 * connection's key (revoked, expired or stripped of a permission). The
 * connection moves to `invalid_credentials`, which blocks link creation
 * (`gateway_not_ready`), and the owners and managers are e-mailed once.
 *
 * Stale-credential guard: the caller passes the fingerprint of the key that
 * actually failed. If the keys were rotated in the meantime (the stored
 * fingerprint changed while the failing call was in flight), nothing
 * happens: a revoked OLD key must not block the NEW one.
 */
final readonly class MarkConnectionCredentialsInvalid
{
    public function __construct(
        private AuditLogger $audit,
        private GatewayNotificationRecipients $recipients,
    ) {}

    public function handle(GatewayConnection $connection, ?string $failedFingerprint): GatewayConnection
    {
        [$updated, $changed] = DB::transaction(function () use ($connection, $failedFingerprint): array {
            $locked = GatewayConnection::query()->lockForUpdate()->findOrFail($connection->id);

            if (! $locked->isApiKey() || $locked->status->isDisconnected()) {
                return [$locked, false];
            }

            if ($failedFingerprint === null || ! hash_equals((string) $locked->credentials_fingerprint, $failedFingerprint)) {
                return [$locked, false];
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => ConnectionStatus::InvalidCredentials,
                'last_health_check_at' => now(),
                'last_health_check_status' => HealthCheckStatus::AuthenticationFailed,
            ])->save();

            if ($from === ConnectionStatus::InvalidCredentials) {
                return [$locked, false];
            }

            $this->audit->record(AuditAction::GatewayCredentialsInvalid, $locked, [
                'before' => ['status' => $from->value],
                'after' => ['status' => ConnectionStatus::InvalidCredentials->value],
                'livemode' => $locked->livemode,
            ]);

            return [$locked, true];
        });

        if ($changed) {
            Notification::send($this->recipients->of($updated->tenant_id), (new GatewayConnectionNotification(ConnectionNotice::InvalidCredentials, $updated->livemode))->locale(app(TenantAccess::class)->defaultLocale($updated->tenant_id)));
        }

        return $updated;
    }
}
