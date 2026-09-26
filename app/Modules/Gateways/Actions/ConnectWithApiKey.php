<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Gateways\Actions\Concerns\ValidatesApiKeys;
use App\Modules\Gateways\Data\ApiKeyConnectionData;
use App\Modules\Gateways\Data\ApiKeyValidationResult;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Enums\ConnectionError;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionNotice;
use App\Modules\Gateways\Enums\ConnectionStatus;
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
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * "Advanced: use my API keys" (plan 12.3.3, ADR-004, ADR-0047). Validates the
 * merchant's restricted + publishable keys, stores the restricted key
 * encrypted with the dedicated key, creates the webhook endpoint on the
 * merchant account and activates the connection. When any step fails,
 * nothing is kept (the half-created row is deleted).
 *
 * The restricted key never reaches logs, exceptions, audit metadata, jobs or
 * the panel: only its fingerprint and last four characters are stored in
 * clear.
 */
final readonly class ConnectWithApiKey
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
    public function handle(User $actor, ApiKeyConnectionData $data): GatewayConnection
    {
        $this->guard($actor, $data);

        if (GatewayConnection::query()->current()->exists()) {
            throw new GatewayConnectionException(ConnectionError::AlreadyConnected);
        }

        // One ID per attempt: every idempotency key of this operation derives
        // from it, so the SDK's retries reuse them and a new attempt never
        // replays an old response (rules.md rule 5).
        $operationId = Ulid::generate();
        $result = $this->validateKeys($data, $operationId);
        $connection = $this->store($actor, $data, $result);
        $context = $this->clients->direct($data->credentials->restrictedKey, $data->credentials->publishableKey);
        $endpoint = null;

        try {
            $endpoint = $this->flow->createWebhookEndpoint(
                $context,
                $this->urls->direct($connection),
                $connection->id,
                'axispay-webhook-endpoint-'.$connection->id.'-'.$operationId,
            );

            $connection = $this->activate($connection, $endpoint, $result, $actor);
        } catch (Throwable $e) {
            // Plan 12.3.3: nothing is kept when the connection cannot be
            // activated, neither the row (with its encrypted key) nor the
            // endpoint on the merchant account.
            if ($endpoint !== null) {
                $this->flow->deleteWebhookEndpoint($context, $endpoint['id']);
            }

            $connection->delete();

            if ($e instanceof ApiKeyValidationException) {
                $this->recordRejection($e);
            }

            throw $e;
        }

        $recipients = $this->recipients->of($connection->tenant_id);
        Notification::send($recipients, new GatewayConnectionNotification(ConnectionNotice::Connected, $connection->livemode));

        if ($result->excessive !== []) {
            Notification::send($recipients, new GatewayConnectionNotification(ConnectionNotice::ExcessivePermissions, $connection->livemode));
        }

        $this->activation->afterGatewayReady($connection);

        return $connection;
    }

    private function store(User $actor, ApiKeyConnectionData $data, ApiKeyValidationResult $result): GatewayConnection
    {
        $credentials = $data->credentials;
        $secret = $this->encrypter->encrypt($credentials->restrictedKey);

        try {
            return DB::transaction(function () use ($actor, $credentials, $secret, $result): GatewayConnection {
                $connection = new GatewayConnection;
                $connection->forceFill([
                    'connection_method' => ConnectionMethod::ApiKey,
                    'provider_account_id' => $result->account->providerAccountId,
                    'country' => $result->account->country,
                    'default_currency' => $result->account->defaultCurrency,
                    'status' => ConnectionStatus::Onboarding,
                    'credentials_secret' => $secret->ciphertext,
                    'credentials_key_version' => $secret->keyVersion,
                    'credentials_publishable' => $credentials->publishableKey,
                    'credentials_fingerprint' => $credentials->fingerprint(),
                    'credentials_last4' => $credentials->last4(),
                    'validated_permissions' => $result->toReport(),
                    'risk_acknowledged_at' => now(),
                    'risk_acknowledged_by_user_id' => $actor->id,
                ])->save();

                return $connection;
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->recordRejection($rejection = new ApiKeyValidationException(ApiKeyRejection::AccountAlreadyLinked, [], $e));

            throw $rejection;
        }
    }

    /**
     * @param  array{id: string, secret: string}  $endpoint
     */
    private function activate(GatewayConnection $connection, array $endpoint, ApiKeyValidationResult $result, User $actor): GatewayConnection
    {
        $webhookSecret = $this->encrypter->encrypt($endpoint['secret']);

        return DB::transaction(function () use ($connection, $endpoint, $webhookSecret, $result, $actor): GatewayConnection {
            $locked = GatewayConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $account = $result->account;

            $locked->forceFill([
                'provider_webhook_endpoint_id' => $endpoint['id'],
                'provider_webhook_secret' => $webhookSecret->ciphertext,
                'credentials_key_version' => $webhookSecret->keyVersion,
                'status' => $account->chargesEnabled ? ConnectionStatus::Active : ConnectionStatus::Restricted,
                'charges_enabled' => $account->chargesEnabled,
                'payouts_enabled' => $account->payoutsEnabled,
                'details_submitted' => $account->detailsSubmitted,
                'requirements' => $account->requirements,
                'connected_at' => now(),
                'last_synced_at' => now(),
                'last_health_check_at' => now(),
                'last_health_check_status' => HealthCheckStatus::Ok,
            ])->save();

            $this->audit->record(AuditAction::GatewayRiskAcknowledged, $locked, [
                'method' => ConnectionMethod::ApiKey->value,
                'acknowledged_by_user_id' => $actor->id,
                'notice_version' => config('axispay.gateways.stripe.api_key_risk_notice_version'),
            ]);

            $this->audit->record(AuditAction::GatewayConnected, $locked, [
                'method' => ConnectionMethod::ApiKey->value,
                'provider_account_id' => $locked->provider_account_id,
                'country' => $locked->country,
                'status' => $locked->status->value,
                'livemode' => $locked->livemode,
                'excessive_permissions' => $result->excessive,
            ]);

            return $locked;
        });
    }
}
