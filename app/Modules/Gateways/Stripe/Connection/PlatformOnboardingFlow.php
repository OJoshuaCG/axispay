<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe\Connection;

use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\StripeClientFactory;
use App\Modules\Gateways\Stripe\StripeErrorMapper;
use Stripe\Exception\ApiErrorException;

/**
 * Stripe-specific steps of the platform_onboarding method (plan 12.3.1):
 * the connected account (Accounts API v1 with the controller properties of
 * config `axispay.gateways.stripe.account_controller`) and the hosted
 * onboarding links (Account Links). Called by the Gateways actions.
 */
final readonly class PlatformOnboardingFlow
{
    public function __construct(private StripeClientFactory $clients) {}

    /**
     * Creates the connected account. The idempotency key is derived from the
     * connection ID, so a retry after a partial failure returns the same
     * account instead of creating a second one (rules.md rule 5).
     *
     * @throws GatewayException
     */
    public function createAccount(GatewayConnection $connection, string $country): string
    {
        $context = $this->clients->platform($connection->livemode);

        try {
            $account = $context->client->accounts->create([
                'country' => $country,
                'controller' => self::controller(),
                'metadata' => [
                    'axispay_tenant_id' => $connection->tenant_id,
                    'axispay_connection_id' => $connection->id,
                ],
            ], $context->options('axispay-account-'.$connection->id));
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'createAccount');
        }

        return $account->id;
    }

    /**
     * Controller properties from config (ADR-0047: Standard-equivalent).
     *
     * @return array{fees: array{payer: string}, losses: array{payments: string}, requirement_collection: string, stripe_dashboard: array{type: string}}
     */
    public static function controller(): array
    {
        $key = 'axispay.gateways.stripe.account_controller.';

        return [
            'fees' => ['payer' => config()->string($key.'fees.payer')],
            'losses' => ['payments' => config()->string($key.'losses.payments')],
            'requirement_collection' => config()->string($key.'requirement_collection'),
            'stripe_dashboard' => ['type' => config()->string($key.'stripe_dashboard.type')],
        ];
    }

    /**
     * A single-use hosted onboarding link (Account Links expire after a few
     * minutes). `$operationId` is unique per link request; stripe-php reuses
     * its idempotency key on network retries.
     *
     * @throws GatewayException
     */
    public function createOnboardingLink(GatewayConnection $connection, string $returnUrl, string $refreshUrl, string $operationId): string
    {
        $context = $this->clients->platform($connection->livemode);

        try {
            $link = $context->client->accountLinks->create([
                'account' => (string) $connection->provider_account_id,
                'type' => 'account_onboarding',
                'return_url' => $returnUrl,
                'refresh_url' => $refreshUrl,
                'collection_options' => ['fields' => 'eventually_due'],
            ], $context->options('axispay-account-link-'.$operationId));
        } catch (ApiErrorException $e) {
            throw StripeErrorMapper::map($e, 'createAccountLink');
        }

        return $link->url;
    }
}
