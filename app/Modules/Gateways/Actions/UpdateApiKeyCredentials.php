<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Actions\Concerns\ValidatesApiKeys;
use App\Modules\Gateways\Data\ApiKeyConnectionData;
use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Data\ApiKeyValidationResult;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Enums\ConnectionError;
use App\Modules\Gateways\Enums\ConnectionNotice;
use App\Modules\Gateways\Enums\HealthCheckStatus;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Exceptions\GatewayConnectionException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Notifications\GatewayConnectionNotification;
use App\Modules\Gateways\Services\GatewayConnectionResolver;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use App\Modules\Gateways\Services\GatewayNotificationRecipients;
use App\Modules\Gateways\Services\GatewayWebhookUrls;
use App\Modules\Gateways\Services\TenantGatewayActivation;
use App\Modules\Gateways\Stripe\Connection\ApiKeyFlow;
use App\Modules\Gateways\Stripe\StripeClientFactory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\Services\TenantAccess;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * "Update keys" (plan 12.3.3, key rotation by the merchant): the same checks
 * as connecting; the account must be the same one (otherwise disconnect and
 * connect again). The webhook endpoint is re-created with the new key and
 * the old one deleted (best effort). Also clears `invalid_credentials`.
 */
final readonly class UpdateApiKeyCredentials
{
    use ValidatesApiKeys;

    public function __construct(
        private ApiKeyFlow $flow,
        private GatewayConnectionResolver $resolver,
        private ReauthenticationWindow $reauthentication,
        private AuditLogger $audit,
        private TenantContext $context,
        private GatewayCredentialsEncrypter $encrypter,
        private StripeClientFactory $clients,
        private GatewayWebhookUrls $urls,
        private GatewayNotificationRecipients $recipients,
        private TenantGatewayActivation $activation,
    ) {}

    /**
     * @throws ApiKeyValidationException
     * @throws GatewayConnectionException
     */
    public function handle(User $actor, GatewayConnection $connection, ApiKeyConnectionData $data): GatewayConnection
    {
        $this->guard($actor, $data, $connection);

        if (! $connection->isApiKey() || $connection->status->isDisconnected()) {
            throw new GatewayConnectionException(ConnectionError::NotApiKey);
        }

        // One ID per attempt (rules.md rule 5): the SDK's retries reuse its
        // keys, and submitting the same key again later is a new operation
        // that never replays the endpoint of an earlier one.
        $operationId = Ulid::generate();
        $credentials = $data->credentials;
        $result = $this->validateKeys($data, $operationId, $connection);
        $context = $this->clients->direct($credentials->restrictedKey, $credentials->publishableKey);

        try {
            $endpoint = $this->flow->createWebhookEndpoint(
                $context,
                $this->urls->direct($connection),
                $connection->id,
                'axispay-webhook-endpoint-'.$connection->id.'-'.$operationId,
            );
        } catch (ApiKeyValidationException $e) {
            $this->recordRejection($e, $connection);

            throw $e;
        }

        try {
            [$updated, $previousEndpoint] = $this->save($actor, $connection, $credentials, $endpoint, $result);
        } catch (Throwable $e) {
            // The new endpoint must not outlive a failed save: the connection
            // keeps its old key and its old endpoint.
            $this->flow->deleteWebhookEndpoint($context, $endpoint['id']);

            if ($e instanceof UniqueConstraintViolationException) {
                // Another tenant stored the same key meanwhile (fingerprint race).
                $rejection = new ApiKeyValidationException(ApiKeyRejection::KeyAlreadyLinked, [], $e);
                $this->recordRejection($rejection, $connection);

                throw $rejection;
            }

            throw $e;
        }

        if ($previousEndpoint !== null && $previousEndpoint !== $endpoint['id']) {
            $this->flow->deleteWebhookEndpoint($context, $previousEndpoint);
        }

        if ($result->excessive !== []) {
            Notification::send($this->recipients->of($updated->tenant_id), (new GatewayConnectionNotification(ConnectionNotice::ExcessivePermissions, $updated->livemode))->locale(app(TenantAccess::class)->defaultLocale($updated->tenant_id)));
        }

        $this->activation->afterGatewayReady($updated);

        return $updated;
    }

    /**
     * @param  array{id: string, secret: string}  $endpoint
     * @return array{0: GatewayConnection, 1: string|null} the updated connection and the endpoint it replaced
     */
    private function save(User $actor, GatewayConnection $connection, ApiKeyCredentials $credentials, array $endpoint, ApiKeyValidationResult $result): array
    {
        $secret = $this->encrypter->encrypt($credentials->restrictedKey);
        $webhookSecret = $this->encrypter->encrypt($endpoint['secret']);
        $account = $result->account;

        return DB::transaction(function () use ($actor, $connection, $credentials, $secret, $webhookSecret, $endpoint, $result, $account): array {
            $locked = GatewayConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $previousEndpoint = $locked->provider_webhook_endpoint_id;
            $from = $locked->status;

            $locked->forceFill([
                'credentials_secret' => $secret->ciphertext,
                'credentials_key_version' => $secret->keyVersion,
                'credentials_publishable' => $credentials->publishableKey,
                'credentials_fingerprint' => $credentials->fingerprint(),
                'credentials_last4' => $credentials->last4(),
                'provider_webhook_endpoint_id' => $endpoint['id'],
                'provider_webhook_secret' => $webhookSecret->ciphertext,
                'validated_permissions' => $result->toReport(),
                'risk_acknowledged_at' => now(),
                'risk_acknowledged_by_user_id' => $actor->id,
                'status' => SyncGatewayConnection::statusFor($locked, $account),
                'country' => $account->country,
                'default_currency' => $account->defaultCurrency,
                'charges_enabled' => $account->chargesEnabled,
                'payouts_enabled' => $account->payoutsEnabled,
                'details_submitted' => $account->detailsSubmitted,
                'requirements' => $account->requirements,
                'last_synced_at' => now(),
                'last_health_check_at' => now(),
                'last_health_check_status' => HealthCheckStatus::Ok,
            ])->save();

            $this->audit->record(AuditAction::GatewayRiskAcknowledged, $locked, [
                'method' => $locked->connection_method->value,
                'acknowledged_by_user_id' => $actor->id,
                'notice_version' => config('axispay.gateways.stripe.api_key_risk_notice_version'),
            ]);

            $this->audit->record(AuditAction::GatewayCredentialsUpdated, $locked, [
                'before' => ['status' => $from->value],
                'after' => ['status' => $locked->status->value],
                'livemode' => $locked->livemode,
                'excessive_permissions' => $result->excessive,
            ]);

            return [$locked, $previousEndpoint];
        });
    }
}
