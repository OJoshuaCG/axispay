<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A merchant Stripe account as seen through the fake HTTP client, for the
 * api_key flow (plan 12.3.3): what the restricted key and the publishable
 * key can do. Defaults: a valid MX account whose key has exactly the
 * required permissions.
 */
final class StripeApiKeyScenario
{
    /** @var array<string, bool> permission => granted */
    private array $permissions = [
        'payment_intent_write' => true,
        'charge_write' => true,
        'charge_read' => true,
        'dispute_read' => true,
        'event_read' => true,
        'payment_method_read' => true,
        'confirmation_token_read' => true,
        'token_read' => true,
        'webhook_write' => true,
        'payout_write' => false,
        'transfer_write' => false,
        'balance_read' => false,
    ];

    private string $country = 'MX';

    private bool $chargesEnabled = true;

    private bool $publishableKeyOtherAccount = false;

    public function __construct(
        private readonly FakeStripeHttpClient $http,
        public readonly string $accountId = 'acct_Merchant0001',
        public readonly string $rkPrefix = 'rk_',
        public readonly string $pkPrefix = 'pk_',
    ) {}

    public function without(string ...$permissions): self
    {
        foreach ($permissions as $permission) {
            $this->permissions[$permission] = false;
        }

        return $this;
    }

    public function with(string ...$permissions): self
    {
        foreach ($permissions as $permission) {
            $this->permissions[$permission] = true;
        }

        return $this;
    }

    public function country(string $country): self
    {
        $this->country = $country;

        return $this;
    }

    public function chargesDisabled(): self
    {
        $this->chargesEnabled = false;

        return $this;
    }

    public function publishableKeyOfAnotherAccount(): self
    {
        $this->publishableKeyOtherAccount = true;

        return $this;
    }

    public function install(): self
    {
        $http = $this->http;
        $rk = $this->rkPrefix;
        $denied = ['error' => ['type' => 'invalid_request_error', 'message' => 'The provided key does not have the required permissions.']];
        $list = static fn (string $url): array => ['object' => 'list', 'data' => [], 'has_more' => false, 'url' => $url];
        $missing = ['error' => ['type' => 'invalid_request_error', 'code' => 'parameter_missing', 'message' => 'Missing required param.']];
        $notFound = ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such object.']];
        $allow = fn (string $permission, int $status, array $body): array => $this->permissions[$permission] ? [$status, $body] : [403, $denied];

        $http->on('get', '/v1/account', StripeFixtures::account($this->accountId, $this->chargesEnabled, $this->country), $rk);
        $http->on('post', '/v1/tokens', ['id' => 'tok_PiiProbe0001', 'object' => 'token', 'type' => 'pii', 'livemode' => false, 'used' => false], $this->pkPrefix);
        $http->on('get', '/v1/tokens/*', fn (): array => $this->publishableKeyOtherAccount
            ? [404, $notFound]
            : $allow('token_read', 200, ['id' => 'tok_PiiProbe0001', 'object' => 'token', 'type' => 'pii']), $rk);
        $http->on('post', '/v1/payment_intents', static fn (): array => $allow('payment_intent_write', 400, $missing), $rk);
        $http->on('post', '/v1/refunds', static fn (): array => $allow('charge_write', 400, $missing), $rk);
        $http->on('get', '/v1/charges', static fn (): array => $allow('charge_read', 200, $list('/v1/charges')), $rk);
        $http->on('get', '/v1/disputes', static fn (): array => $allow('dispute_read', 200, $list('/v1/disputes')), $rk);
        $http->on('get', '/v1/events', static fn (): array => $allow('event_read', 200, $list('/v1/events')), $rk);
        $http->on('get', '/v1/payment_methods', static fn (): array => $allow('payment_method_read', 200, $list('/v1/payment_methods')), $rk);
        $http->on('get', '/v1/confirmation_tokens/*', static fn (): array => $allow('confirmation_token_read', 404, $notFound), $rk);
        $http->on('post', '/v1/payouts', static fn (): array => $allow('payout_write', 400, $missing), $rk);
        $http->on('post', '/v1/transfers', static fn (): array => $allow('transfer_write', 400, $missing), $rk);
        $http->on('get', '/v1/balance', static fn (): array => $allow('balance_read', 200, ['object' => 'balance', 'available' => [], 'pending' => []]), $rk);
        $http->on('post', '/v1/webhook_endpoints', static fn (array $request): array => $allow('webhook_write', 200, [
            'id' => 'we_'.substr(hash('sha256', (string) ($request['headers']['idempotency-key'] ?? '')), 0, 16),
            'object' => 'webhook_endpoint',
            'secret' => 'whsec_FakeEndpointSecret0001',
            'url' => $request['params']['url'] ?? null,
            'enabled_events' => $request['params']['enabled_events'] ?? [],
            'api_version' => $request['params']['api_version'] ?? null,
            'status' => 'enabled',
        ]), $rk);
        $http->on('delete', '/v1/webhook_endpoints/*', static fn (array $request): array => [200, ['id' => basename($request['path']), 'object' => 'webhook_endpoint', 'deleted' => true]], $rk);

        return $this;
    }
}
