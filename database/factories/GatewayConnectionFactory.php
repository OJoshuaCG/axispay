<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Services\GatewayCredentialsEncrypter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Create inside a tenant context (TenantContext::runAsTenant), like every
 * tenant model; `tenant_id` and `livemode` come from the context.
 *
 * @extends Factory<GatewayConnection>
 */
class GatewayConnectionFactory extends Factory
{
    protected $model = GatewayConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'stripe',
            'connection_method' => ConnectionMethod::PlatformOnboarding,
            'provider_account_id' => 'acct_'.Str::random(16),
            'country' => 'MX',
            'default_currency' => 'mxn',
            'status' => ConnectionStatus::Active,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => ['currently_due' => [], 'eventually_due' => [], 'past_due' => [], 'disabled_reason' => null],
            'connected_at' => now(),
            'last_synced_at' => now(),
        ];
    }

    public function onboarding(): static
    {
        return $this->state(fn (): array => [
            'status' => ConnectionStatus::Onboarding,
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
            'connected_at' => null,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (): array => [
            'status' => ConnectionStatus::Disconnected,
            'disconnected_at' => now(),
            'disconnect_reason' => 'user_requested',
        ]);
    }

    /**
     * An api_key connection with encrypted credentials. The secret given
     * here is fake: tests pair it with the fake Stripe HTTP client.
     */
    public function apiKey(string $secret = 'rk_test_factorysecret0000A1B2', string $publishable = 'pk_test_factorypublishable'): static
    {
        return $this->state(function () use ($secret, $publishable): array {
            $encrypter = app(GatewayCredentialsEncrypter::class);
            $encrypted = $encrypter->encrypt($secret);

            return [
                'connection_method' => ConnectionMethod::ApiKey,
                'credentials_secret' => $encrypted->ciphertext,
                'credentials_key_version' => $encrypted->keyVersion,
                'credentials_publishable' => $publishable,
                'credentials_fingerprint' => hash('sha256', $secret),
                'credentials_last4' => substr($secret, -4),
                'provider_webhook_endpoint_id' => 'we_'.Str::random(16),
                'provider_webhook_secret' => $encrypter->encrypt('whsec_'.Str::random(24))->ciphertext,
                'validated_permissions' => ['missing' => [], 'excessive' => []],
                'risk_acknowledged_at' => now(),
            ];
        });
    }
}
