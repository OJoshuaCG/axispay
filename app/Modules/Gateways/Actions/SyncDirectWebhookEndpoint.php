<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Actions;

use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\Connection\ApiKeyFlow;
use App\Modules\Gateways\Stripe\StripeClientFactory;

/**
 * Updates the events of an api_key connection's remote webhook endpoint to
 * the configured list (ADR-0047), used by
 * `axispay:stripe-sync-webhook-endpoints` when a phase widens the
 * subscription. Runs in the connection's tenant context. A rejected key
 * marks the connection `invalid_credentials` (same stale-key guard as the
 * health check).
 */
final readonly class SyncDirectWebhookEndpoint
{
    public function __construct(
        private StripeClientFactory $clients,
        private ApiKeyFlow $flow,
        private MarkConnectionCredentialsInvalid $markInvalid,
    ) {}

    /**
     * @param  string  $operationId  unique per run; stable across the SDK's retries
     *
     * @throws GatewayException when the gateway refuses or is unavailable
     */
    public function handle(GatewayConnection $connection, string $operationId): bool
    {
        if (! $connection->isApiKey() || $connection->status->isDisconnected() || $connection->provider_webhook_endpoint_id === null) {
            return false;
        }

        $fingerprint = $connection->credentials_fingerprint;

        try {
            $this->flow->updateWebhookEndpoint(
                $this->clients->for($connection),
                $connection->provider_webhook_endpoint_id,
                'axispay-webhook-sync-'.$connection->id.'-'.$operationId,
            );
        } catch (GatewayAuthenticationException $e) {
            $this->markInvalid->handle($connection, $fingerprint);

            throw $e;
        }

        return true;
    }
}
