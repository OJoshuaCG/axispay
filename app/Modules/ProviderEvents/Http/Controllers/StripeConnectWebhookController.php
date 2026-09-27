<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Http\Controllers;

use App\Modules\Gateways\Data\WebhookSource;
use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Gateways\Services\GatewayConnectionResolver;
use App\Modules\Gateways\Services\GatewayFactory;
use App\Modules\ProviderEvents\Actions\RecordProviderEvent;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /webhooks/stripe/connect/{mode}` on the API host (plan 14.1, 14.2):
 * events of the platform_onboarding / oauth connected accounts. The URL mode
 * only picks the signing secret; the event is routed by its own `livemode`
 * and `account` (Stripe also sends test events of connected accounts to live
 * Connect endpoints). Verify, store, queue, answer 200: nothing heavy here.
 * Outside CSRF, sessions and API-key authentication by construction (own
 * route group, `api` middleware).
 */
final class StripeConnectWebhookController
{
    public function __invoke(
        Request $request,
        string $mode,
        GatewayFactory $gateways,
        GatewayConnectionResolver $resolver,
        RecordProviderEvent $record,
    ): JsonResponse {
        try {
            $event = $gateways->for(GatewayProvider::Stripe)
                ->parseWebhook($request->getContent(), $request->headers->all(), WebhookSource::connect($mode === 'live'));
        } catch (InvalidWebhookSignatureException) {
            Log::warning('Stripe webhook signature verification failed.', ['metric' => 'stripe_webhook_signature_failures', 'endpoint' => 'connect', 'mode' => $mode]);

            throw ApiException::of(ApiErrorCode::ParameterInvalid, 'The webhook signature is invalid.');
        }

        $connection = match (true) {
            $event->providerAccountId === null => null,
            // A payment event belongs to the connection that created its attempt (ADR-0051).
            $event->attemptReference !== null => $resolver->forPaymentAttempt(GatewayProvider::Stripe, $event->attemptReference, $event->providerAccountId, $event->livemode)
                ?? $resolver->forProviderAccount(GatewayProvider::Stripe, $event->providerAccountId, $event->livemode),
            default => $resolver->forProviderAccount(GatewayProvider::Stripe, $event->providerAccountId, $event->livemode),
        };

        // The Connect endpoint only serves Connect connections (plan 14.1).
        if ($connection !== null && ! $connection->connection_method->usesConnect()) {
            $connection = null;
        }

        $record->handle(GatewayProvider::Stripe, $event, $connection);

        return new JsonResponse(['received' => true]);
    }
}
