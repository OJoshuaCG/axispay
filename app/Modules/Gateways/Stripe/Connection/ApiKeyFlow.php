<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe\Connection;

use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Data\ApiKeyValidationResult;
use App\Modules\Gateways\Data\ConnectedAccountData;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Stripe\PlatformStripeKeys;
use App\Modules\Gateways\Stripe\StripeAccountMapper;
use App\Modules\Gateways\Stripe\StripeCallContext;
use App\Modules\Gateways\Stripe\StripeClientFactory;
use App\Modules\Gateways\Stripe\StripeErrorMapper;
use Closure;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe-specific checks and remote set-up of the api_key method (plan
 * 12.3.3, ADR-004, ADR-0047). Used by the ConnectWithApiKey and
 * UpdateApiKeyCredentials actions; nothing is stored here.
 *
 * Validation order (the first failure stops everything):
 *  1. format: a restricted key (`rk_`); any `sk_` is refused;
 *  2. modes: rk and pk share a mode, and it is the panel's mode;
 *  3. GET /v1/account with the rk: the account, its country (allowed list);
 *  4. the pk belongs to the same account: a single-use PII token is created
 *     with the pk and must be readable with the rk (404 = other account);
 *  5. required permissions, probed without side effects;
 *  6. dangerous permissions (payouts, transfers, balance) are reported.
 *
 * The permission lists live in StripeKeyPermissions, which the panel's
 * help also reads.
 *
 * Probes (ADR-0047): read permissions with `limit=1` lists or a GET of a
 * non-existent ID (404 = allowed, 403 = denied); write permissions with an
 * empty POST that Stripe must reject for missing parameters (400 = allowed,
 * 403 = denied). Nothing can be created by an empty body. Stripe does not
 * document that permission is checked before parameter validation, so the
 * `stripe` contract tests confirm it against a real test-mode key.
 */
final readonly class ApiKeyFlow
{
    /** A dummy value: the PII token only proves which account the pk belongs to. */
    private const string PROBE_ID_NUMBER = '000000000';

    public function __construct(
        private StripeClientFactory $clients,
        private PlatformStripeKeys $platformKeys,
    ) {}

    /**
     * Steps 1 and 2: local checks, no network call.
     *
     * @throws ApiKeyValidationException
     */
    public function checkFormat(ApiKeyCredentials $credentials, bool $livemode): void
    {
        $secret = $credentials->restrictedKey;
        $publishable = $credentials->publishableKey;

        if (str_starts_with($secret, 'sk_')) {
            throw new ApiKeyValidationException(ApiKeyRejection::SecretKeyNotAllowed);
        }

        if (preg_match('/^rk_(test|live)_[A-Za-z0-9]{8,}$/D', $secret, $secretMatch) !== 1) {
            throw new ApiKeyValidationException(ApiKeyRejection::NotARestrictedKey);
        }

        if (preg_match('/^pk_(test|live)_[A-Za-z0-9]{8,}$/D', $publishable, $publishableMatch) !== 1) {
            throw new ApiKeyValidationException(ApiKeyRejection::InvalidPublishableKey);
        }

        if ($secretMatch[1] !== $publishableMatch[1]) {
            throw new ApiKeyValidationException(ApiKeyRejection::KeyModesDiffer);
        }

        if (($secretMatch[1] === 'live') !== $livemode) {
            throw new ApiKeyValidationException(ApiKeyRejection::PanelModeMismatch);
        }
    }

    /**
     * Steps 1 to 6. `$operationId` makes the idempotency keys of this
     * validation deterministic (rules.md rule 5).
     *
     * @throws ApiKeyValidationException
     */
    public function validate(ApiKeyCredentials $credentials, bool $livemode, string $operationId): ApiKeyValidationResult
    {
        $this->checkFormat($credentials, $livemode);

        $context = $this->clients->direct($credentials->restrictedKey, $credentials->publishableKey);
        $account = $this->retrieveAccount($context);

        if ($account->country === null || ! in_array($account->country, self::allowedCountries(), true)) {
            throw new ApiKeyValidationException(ApiKeyRejection::CountryNotAllowed, [$account->country ?? '?']);
        }

        $missing = $this->verifyPublishableKey($context, $credentials->publishableKey, $operationId) ? [] : ['token_read'];
        $granted = array_values(array_diff(StripeKeyPermissions::IMPLICIT, ['webhook_write'], $missing));

        foreach (StripeKeyPermissions::REQUIRED_PROBES as $permission => $probe) {
            if ($this->probe($context, $probe, "{$operationId}-{$permission}")) {
                $granted[] = $permission;
            } else {
                $missing[] = $permission;
            }
        }

        if ($missing !== []) {
            throw new ApiKeyValidationException(ApiKeyRejection::MissingPermissions, $missing);
        }

        $excessive = [];

        foreach (StripeKeyPermissions::DANGEROUS_PROBES as $permission => $probe) {
            if ($this->probe($context, $probe, "{$operationId}-{$permission}")) {
                $excessive[] = $permission;
            }
        }

        return new ApiKeyValidationResult($account, $granted, $excessive);
    }

    /**
     * Creates the webhook endpoint on the merchant account (plan 12.3.3) with
     * the pinned API version and the direct events of plan 14.3.
     *
     * @return array{id: string, secret: string}
     *
     * @throws ApiKeyValidationException
     */
    public function createWebhookEndpoint(StripeCallContext $context, string $url, string $connectionId, string $idempotencyKey): array
    {
        try {
            $endpoint = $context->client->webhookEndpoints->create([
                'url' => $url,
                'enabled_events' => self::directEvents(),
                'api_version' => $this->platformKeys->apiVersion(),
                'description' => config()->string('axispay.display_name').' ('.$connectionId.'). Managed automatically; do not edit or delete.',
                'metadata' => ['axispay_connection_id' => $connectionId],
            ], $context->options($idempotencyKey));
        } catch (ApiErrorException $e) {
            $mapped = StripeErrorMapper::map($e, 'createWebhookEndpoint');

            throw match (true) {
                $mapped instanceof GatewayAuthenticationException && $mapped->isPermissionDenied() => new ApiKeyValidationException(ApiKeyRejection::MissingPermissions, ['webhook_write'], $mapped),
                $mapped instanceof GatewayUnavailableException => new ApiKeyValidationException(ApiKeyRejection::GatewayUnavailable, [], $mapped),
                default => new ApiKeyValidationException(ApiKeyRejection::WebhookEndpointFailed, [], $mapped),
            };
        }

        $secret = $endpoint->secret ?? null;

        if (! is_string($secret) || $secret === '') {
            throw new ApiKeyValidationException(ApiKeyRejection::WebhookEndpointFailed);
        }

        return ['id' => $endpoint->id, 'secret' => $secret];
    }

    /**
     * Brings an existing merchant endpoint in line with the configured events
     * (`axispay:stripe-sync-webhook-endpoints`), so a later phase can widen
     * the subscription without reconnecting. The API version of an endpoint
     * is fixed when it is created (Stripe's update does not take it): a new
     * pinned version needs the endpoints re-created ("Update keys").
     *
     * @throws GatewayException
     */
    public function updateWebhookEndpoint(StripeCallContext $context, string $endpointId, string $idempotencyKey): void
    {
        try {
            $context->client->webhookEndpoints->update($endpointId, [
                'enabled_events' => self::directEvents(),
            ], $context->options($idempotencyKey));
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'updateWebhookEndpoint');
        }
    }

    /**
     * Events of the direct endpoints (config, plan 14.3).
     *
     * @return list<string>
     */
    public static function directEvents(): array
    {
        $events = config('axispay.gateways.stripe.direct_webhook_events');

        return is_array($events) ? array_values(array_filter($events, is_string(...))) : [];
    }

    /**
     * Best effort (plan 12.3.3): the key may already be revoked. Returns
     * whether the endpoint is gone.
     */
    public function deleteWebhookEndpoint(StripeCallContext $context, string $endpointId): bool
    {
        try {
            $context->client->webhookEndpoints->delete($endpointId, null, $context->options());

            return true;
        } catch (ApiErrorException $e) {
            $mapped = StripeErrorMapper::map($e, 'deleteWebhookEndpoint');

            if ($mapped->httpStatus === 404) {
                return true;
            }

            Log::warning('The remote Stripe webhook endpoint could not be deleted.', [
                'webhook_endpoint_id' => $endpointId,
                'stripe_code' => $mapped->providerCode,
                'http_status' => $mapped->httpStatus,
            ]);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    public static function allowedCountries(): array
    {
        $countries = config('axispay.gateways.stripe.allowed_countries');

        return is_array($countries) ? array_values(array_filter($countries, is_string(...))) : [];
    }

    /**
     * @throws ApiKeyValidationException
     */
    private function retrieveAccount(StripeCallContext $context): ConnectedAccountData
    {
        try {
            return StripeAccountMapper::toData($context->client->accounts->retrieve(null, null, $context->options()));
        } catch (ApiErrorException $e) {
            $mapped = StripeErrorMapper::map($e, 'validateApiKey');

            throw match (true) {
                $mapped instanceof GatewayAuthenticationException => new ApiKeyValidationException(
                    $mapped->isPermissionDenied() ? ApiKeyRejection::AccountNotReadable : ApiKeyRejection::KeyRejected,
                    $mapped->isPermissionDenied() ? ['connected_account_read'] : [],
                    $mapped,
                ),
                $mapped instanceof GatewayUnavailableException => new ApiKeyValidationException(ApiKeyRejection::GatewayUnavailable, [], $mapped),
                default => new ApiKeyValidationException(ApiKeyRejection::KeyRejected, [], $mapped),
            };
        }
    }

    /**
     * Step 4. Returns false when the rk lacks the Tokens read permission
     * (reported as a missing permission by the caller).
     *
     * @throws ApiKeyValidationException
     */
    private function verifyPublishableKey(StripeCallContext $context, string $publishableKey, string $operationId): bool
    {
        try {
            $token = $this->clients->publishable($publishableKey)->client->tokens->create(
                ['pii' => ['id_number' => self::PROBE_ID_NUMBER]],
                ['idempotency_key' => "axispay-pk-check-{$operationId}"],
            );
        } catch (ApiErrorException $e) {
            $mapped = StripeErrorMapper::map($e, 'verifyPublishableKey');

            throw new ApiKeyValidationException(
                $mapped instanceof GatewayUnavailableException ? ApiKeyRejection::GatewayUnavailable : ApiKeyRejection::PublishableKeyRejected,
                [],
                $mapped,
            );
        }

        try {
            $context->client->tokens->retrieve($token->id, null, $context->options());

            return true;
        } catch (ApiErrorException $e) {
            $mapped = StripeErrorMapper::map($e, 'verifyPublishableKey');

            return match (true) {
                $mapped->httpStatus === 404 => throw new ApiKeyValidationException(ApiKeyRejection::PublishableKeyOtherAccount, [], $mapped),
                $mapped instanceof GatewayAuthenticationException && $mapped->isPermissionDenied() => false,
                default => throw self::abort($mapped),
            };
        }
    }

    /**
     * @param  array{0: 'get'|'post', 1: string}  $probe
     *
     * @throws ApiKeyValidationException
     */
    private function probe(StripeCallContext $context, array $probe, string $idempotencyKey): bool
    {
        [$method, $path] = $probe;

        // An empty POST body: Stripe answers 400 (missing parameters) when the
        // key may write, 403 when it may not. Nothing can be created.
        return $this->allowed(static fn (): mixed => $method === 'post'
            ? $context->client->rawRequest('post', $path, [], $context->options('axispay-probe-'.$idempotencyKey))
            : $context->client->rawRequest('get', $path, null, $context->options()));
    }

    /**
     * @param  Closure(): mixed  $call
     *
     * @throws ApiKeyValidationException
     */
    private function allowed(Closure $call): bool
    {
        try {
            $call();

            return true;
        } catch (ApiErrorException $e) {
            $mapped = StripeErrorMapper::map($e, 'probePermission');

            return match (true) {
                $mapped instanceof GatewayAuthenticationException && $mapped->isPermissionDenied() => false,
                $mapped instanceof GatewayAuthenticationException, $mapped instanceof GatewayUnavailableException => throw self::abort($mapped),
                // 400 (missing parameters) or 404 (no such object): the key
                // reached the resource, so it holds the permission.
                default => true,
            };
        }
    }

    private static function abort(GatewayException $mapped): ApiKeyValidationException
    {
        return new ApiKeyValidationException(
            $mapped instanceof GatewayUnavailableException ? ApiKeyRejection::GatewayUnavailable : ApiKeyRejection::KeyRejected,
            [],
            $mapped,
        );
    }
}
