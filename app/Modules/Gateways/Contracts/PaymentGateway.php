<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Contracts;

use App\Modules\Gateways\Data\CheckoutClientConfig;
use App\Modules\Gateways\Data\ConnectedAccountData;
use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Data\PaymentRequest;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\Gateways\Data\ProviderWebhookEvent;
use App\Modules\Gateways\Data\RefundRequest;
use App\Modules\Gateways\Data\WebhookSource;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Enums\ProviderEventKind;
use App\Modules\Gateways\Exceptions\GatewayAuthenticationException;
use App\Modules\Gateways\Exceptions\GatewayOperationNotImplementedException;
use App\Modules\Gateways\Exceptions\GatewayRequestException;
use App\Modules\Gateways\Exceptions\GatewayUnavailableException;
use App\Modules\Gateways\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Gateways\Models\GatewayConnection;

/**
 * The payment gateway port (plan 12.1, ADR-019). The payments domain only
 * talks to this interface; nothing outside the adapter knows gateway IDs,
 * statuses or connection methods. Connection flows (Account Links, OAuth,
 * API keys) are provider-specific and live next to the adapter.
 *
 * Phase 2 implements the account, client-config and webhook methods. The
 * payment and refund methods keep the plan's signatures and throw
 * GatewayOperationNotImplementedException until Phases 4 and 7 fill them.
 *
 * Errors: GatewayAuthenticationException (credentials rejected or access
 * revoked), GatewayUnavailableException (network, rate limit, 5xx: retry
 * with the same idempotency key), GatewayRequestException (anything else).
 */
interface PaymentGateway
{
    public function provider(): GatewayProvider;

    /**
     * @throws GatewayAuthenticationException
     * @throws GatewayUnavailableException
     * @throws GatewayRequestException
     */
    public function retrieveAccount(GatewayConnection $connection): ConnectedAccountData;

    public function clientConfig(GatewayConnection $connection): CheckoutClientConfig;

    /** @throws GatewayOperationNotImplementedException until Phase 4 */
    public function inspectPaymentMethod(GatewayConnection $connection, string $confirmationToken): PaymentMethodPreview;

    /** @throws GatewayOperationNotImplementedException until Phase 4 */
    public function createOrUpdatePayment(GatewayConnection $connection, PaymentRequest $request): ProviderPayment;

    /** @throws GatewayOperationNotImplementedException until Phase 4 */
    public function confirmPayment(GatewayConnection $connection, string $providerPaymentId, string $confirmationToken, string $idempotencyKey): ProviderPayment;

    /** @throws GatewayOperationNotImplementedException until Phase 4 */
    public function retrievePayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment;

    /** @throws GatewayOperationNotImplementedException until Phase 4 */
    public function cancelPayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment;

    /** @throws GatewayOperationNotImplementedException until Phase 7 */
    public function refund(GatewayConnection $connection, RefundRequest $request): ProviderRefund;

    /** @throws GatewayOperationNotImplementedException until Phase 7 */
    public function retrieveRefund(GatewayConnection $connection, string $providerRefundId): ProviderRefund;

    /**
     * Provider-neutral kind of a stored provider event type. `$direct` is true
     * for events received on a connection's own endpoint (api_key), where
     * deauthorization does not exist. Addition to plan 12.1 (ADR-0047).
     */
    public function eventKind(string $providerEventType, bool $direct): ProviderEventKind;

    /**
     * The event envelope without the object's data (plan 14.4: what is kept
     * for ignored or unroutable events, and for old processed ones). Must be
     * idempotent. Addition to plan 12.1 (ADR-0047).
     */
    public function reduceWebhookPayload(string $payload): string;

    /**
     * Verifies the signature on the RAW body and returns the event.
     *
     * @param  array<string, list<string|null>|string|null>  $headers
     *
     * @throws InvalidWebhookSignatureException
     */
    public function parseWebhook(string $rawBody, array $headers, WebhookSource $source): ProviderWebhookEvent;
}
