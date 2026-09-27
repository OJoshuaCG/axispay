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
 * Phase 2 implemented the account, client-config and webhook methods;
 * Phase 4 the payment methods (ADR-0051). Refunds keep the plan's
 * signatures and throw GatewayOperationNotImplementedException until
 * Phase 7.
 *
 * Payments are card-only (ADR-018) and authorized with a separate capture
 * (ADR-0050): confirming authorizes, capturePayment() takes the money and
 * cancelPayment() voids an authorization (or cancels a payment that was
 * never confirmed). Every call that creates or modifies takes an
 * idempotency key; a retry uses the same key (rules.md rule 5).
 *
 * A declined card is not an exception: confirmPayment() returns the payment
 * with its `failure` (plan 12.6).
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

    /**
     * Card country, brand and last four digits behind a confirmation token
     * created in the payer's browser (plan 11.4).
     *
     * @throws GatewayUnavailableException
     * @throws GatewayRequestException
     */
    public function inspectPaymentMethod(GatewayConnection $connection, string $confirmationToken): PaymentMethodPreview;

    /**
     * Creates the payment (manual capture, card only) or, with
     * `providerPaymentId`, updates its amount and currency before it is
     * confirmed (plan 11.4).
     *
     * @throws GatewayUnavailableException
     * @throws GatewayRequestException
     */
    public function createOrUpdatePayment(GatewayConnection $connection, PaymentRequest $request): ProviderPayment;

    /**
     * Confirms with the confirmation token: authorizes the card, possibly
     * asking for 3D Secure (`requires_action` + client secret). `returnUrl`
     * is where a redirect-based authentication comes back to. The payer's
     * receipt e-mail travels here, not at creation: creation only carries
     * what the link fixes, so its idempotency key never meets other values.
     *
     * @throws GatewayUnavailableException
     * @throws GatewayRequestException
     */
    public function confirmPayment(GatewayConnection $connection, string $providerPaymentId, string $confirmationToken, string $idempotencyKey, string $returnUrl, ?string $receiptEmail = null): ProviderPayment;

    /**
     * @throws GatewayUnavailableException
     * @throws GatewayRequestException
     */
    public function retrievePayment(GatewayConnection $connection, string $providerPaymentId): ProviderPayment;

    /**
     * Captures the full authorized amount (ADR-0050 step 5).
     *
     * @throws GatewayUnavailableException
     * @throws GatewayRequestException
     */
    public function capturePayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment;

    /**
     * Cancels a payment that is not captured: voids an authorization (no
     * money moves, ADR-0050) or closes an unconfirmed payment.
     *
     * @throws GatewayUnavailableException
     * @throws GatewayRequestException
     */
    public function cancelPayment(GatewayConnection $connection, string $providerPaymentId, string $idempotencyKey): ProviderPayment;

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
