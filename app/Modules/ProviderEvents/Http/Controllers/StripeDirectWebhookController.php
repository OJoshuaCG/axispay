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
 * `POST /webhooks/stripe/direct/{connection_id}` on the API host (plan 14.1,
 * 14.2): events of one api_key connection, signed with that connection's own
 * secret. Unknown or disconnected connection -> 404 without details (its
 * credentials, and so the means to delete the remote endpoint, were already
 * destroyed at disconnection). The event must belong to the connection's
 * account and mode.
 */
final class StripeDirectWebhookController
{
    public function __invoke(
        Request $request,
        string $connection,
        GatewayFactory $gateways,
        GatewayConnectionResolver $resolver,
        RecordProviderEvent $record,
    ): JsonResponse {
        $model = $resolver->forDirectWebhook($connection);

        if ($model === null || $model->status->isDisconnected()) {
            throw ApiException::of(ApiErrorCode::ResourceNotFound);
        }

        try {
            $event = $gateways->for($model->provider)
                ->parseWebhook($request->getContent(), $request->headers->all(), WebhookSource::direct($model));

            if ($event->livemode !== $model->livemode) {
                throw new InvalidWebhookSignatureException('The event mode does not match the connection.');
            }
        } catch (InvalidWebhookSignatureException) {
            Log::warning('Stripe webhook signature verification failed.', ['metric' => 'stripe_webhook_signature_failures', 'endpoint' => 'direct', 'connection_id' => $model->id]);

            throw ApiException::of(ApiErrorCode::ParameterInvalid, 'The webhook signature is invalid.');
        }

        $record->handle(GatewayProvider::Stripe, $event, $model);

        return new JsonResponse(['received' => true]);
    }
}
