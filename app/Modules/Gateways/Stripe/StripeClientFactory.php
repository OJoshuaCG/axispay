<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Exceptions\GatewayConfigurationException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use SensitiveParameter;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

/**
 * The ONLY place that builds a StripeClient (rules.md rule 5b, plan 12.4.1).
 * Every client pins the API version from config and retries network errors
 * with the same idempotency key.
 *
 * With api_key the merchant's restricted key is decrypted in memory for the
 * call; it is never logged, cached or serialized.
 */
final readonly class StripeClientFactory
{
    public function __construct(
        private PlatformStripeKeys $platformKeys,
        private GatewayCredentialsEncrypter $encrypter,
    ) {}

    public function for(GatewayConnection $connection): StripeCallContext
    {
        return match ($connection->connection_method) {
            ConnectionMethod::PlatformOnboarding,
            ConnectionMethod::OAuth => StripeCallContext::connect(
                client: $this->client($this->platformKeys->secret($connection->livemode)),
                stripeAccount: $connection->provider_account_id
                    ?? throw new GatewayConfigurationException('The connection has no gateway account yet.'),
                publishableKey: $this->platformKeys->publishable($connection->livemode),
            ),
            ConnectionMethod::ApiKey => StripeCallContext::direct(
                client: $this->client($this->encrypter->decrypt(
                    $connection->credentials_secret ?? throw new GatewayConfigurationException('The connection has no stored credentials.'),
                    $connection->credentials_key_version,
                )),
                publishableKey: $connection->credentials_publishable,
            ),
        };
    }

    /** Platform-level calls: creating connected accounts and Account Links. */
    public function platform(bool $livemode): StripeCallContext
    {
        return StripeCallContext::platform($this->client($this->platformKeys->secret($livemode)));
    }

    /** Merchant keys being validated, before anything is stored (api_key). */
    public function direct(#[SensitiveParameter] string $restrictedKey, string $publishableKey): StripeCallContext
    {
        return StripeCallContext::direct($this->client($restrictedKey), $publishableKey);
    }

    /**
     * A client authenticated with a publishable key: only used to create the
     * token that proves the pk and the rk belong to the same account.
     */
    public function publishable(string $publishableKey): StripeCallContext
    {
        return StripeCallContext::direct($this->client($publishableKey), $publishableKey);
    }

    private function client(#[SensitiveParameter] string $apiKey): StripeClient
    {
        $retries = config('services.stripe.max_network_retries');
        $timeout = config('services.stripe.timeout_seconds');
        $connectTimeout = config('services.stripe.connect_timeout_seconds');

        // Bounded calls (ADR-0051): a call never outlives the attempt lease.
        $http = CurlClient::instance();

        if ($http instanceof CurlClient) { // the SDK's untyped singleton
            $http->setTimeout(is_int($timeout) ? $timeout : 20);
            $http->setConnectTimeout(is_int($connectTimeout) ? $connectTimeout : 5);
        }

        return new StripeClient([
            'api_key' => $apiKey,
            'stripe_version' => $this->platformKeys->apiVersion(),
            'max_network_retries' => is_int($retries) ? $retries : 1,
        ]);
    }
}
