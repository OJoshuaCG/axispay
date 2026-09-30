<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Shared\Database\Transactions;
use App\Modules\Webhooks\Data\DeliveryOutcome;
use App\Modules\Webhooks\Enums\WebhookDeliveryError;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Sends one signed webhook (plan 15.5-15.7):
 *
 *  - the SSRF guard runs again right before the call, and the client
 *    connects only to the addresses it validated (CURLOPT_RESOLVE);
 *  - POST with a 5 s connection timeout and 10 s in total, the DNS lookup
 *    of the SSRF guard included (the HTTP call gets what the lookup left);
 *    redirects never followed, any 2xx is success;
 *  - at most `max_response_bytes` are read and 2 KB are kept, sanitized.
 *
 * Never called inside a database transaction (rules.md 7b): the call waits
 * for the merchant's server. Secrets never reach logs or exceptions.
 */
final readonly class WebhookSender
{
    public function __construct(
        private DestinationGuard $guard,
        private WebhookSigner $signer,
        private ResponseExcerpt $excerpts,
    ) {}

    public function send(WebhookEndpoint $endpoint, WebhookEvent $event): DeliveryOutcome
    {
        if (Transactions::open()) {
            throw new LogicException('A webhook is never sent inside a database transaction.');
        }

        $started = hrtime(true);
        $total = (float) max(1, config()->integer('axispay.webhooks.timeout_seconds'));
        $connect = (float) max(1, config()->integer('axispay.webhooks.connect_timeout_seconds'));

        try {
            $destination = $this->guard->inspect($endpoint->url, $endpoint->livemode, DestinationGuard::clock() + $total);
        } catch (UnsafeDestinationException) {
            // A lookup cut short by the budget is a timeout, not a blocked URL.
            return $this->remaining($started, $total) <= 0.05
                ? DeliveryOutcome::failed(WebhookDeliveryError::Timeout, $this->elapsedMs($started))
                : DeliveryOutcome::failed(WebhookDeliveryError::BlockedDestination);
        }

        $remaining = $this->remaining($started, $total);

        if ($remaining <= 0.05) {
            return DeliveryOutcome::failed(WebhookDeliveryError::Timeout, $this->elapsedMs($started));
        }

        $webhookId = $event->prefixedId();
        $timestamp = CarbonImmutable::now()->getTimestamp();
        $body = $event->body();
        $maxBytes = max(1024, config()->integer('axispay.webhooks.max_response_bytes'));
        $headerStatus = null;
        $tooLarge = false;

        try {
            $response = Http::withHeaders([
                'webhook-id' => $webhookId,
                'webhook-timestamp' => (string) $timestamp,
                'webhook-signature' => $this->signer->header($endpoint->signingSecrets(), $webhookId, $timestamp, $body),
                'user-agent' => config()->string('axispay.webhooks.user_agent'),
            ])
                ->withBody($body, 'application/json')
                ->connectTimeout(min($connect, $remaining))
                ->timeout($remaining)
                ->withoutRedirecting()
                ->withOptions([
                    'curl' => [CURLOPT_RESOLVE => $destination->curlResolve()],
                    'on_headers' => static function (ResponseInterface $headers) use (&$headerStatus): void {
                        $headerStatus = $headers->getStatusCode();
                    },
                    'progress' => static function (mixed $downloadTotal, mixed $downloaded) use ($maxBytes, &$tooLarge): void {
                        if (is_int($downloaded) && $downloaded > $maxBytes) {
                            $tooLarge = true;

                            throw new RuntimeException('Webhook response too large.');
                        }
                    },
                ])
                ->post($destination->url);
        } catch (Throwable $e) {
            $duration = $this->elapsedMs($started);

            if ($tooLarge) {
                return is_int($headerStatus) && $headerStatus >= 200 && $headerStatus < 300
                    ? new DeliveryOutcome(true, $headerStatus, null, $duration, null)
                    : DeliveryOutcome::failed(WebhookDeliveryError::ResponseTooLarge, $duration, $headerStatus);
            }

            return DeliveryOutcome::failed($this->classify($e), $duration);
        }

        $duration = $this->elapsedMs($started);
        $status = $response->status();
        $excerpt = $this->excerpt($response->body());

        if ($status >= 200 && $status < 300) {
            return new DeliveryOutcome(true, $status, $excerpt, $duration, null);
        }

        return DeliveryOutcome::failed(WebhookDeliveryError::HttpStatus, $duration, $status, $excerpt);
    }

    private function classify(Throwable $e): WebhookDeliveryError
    {
        if (! CurlFailure::isTransport($e)) {
            return WebhookDeliveryError::ConnectionError;
        }

        return match (true) {
            CurlFailure::isTimeout($e) => WebhookDeliveryError::Timeout,
            CurlFailure::isDns($e) => WebhookDeliveryError::DnsError,
            CurlFailure::isTls($e) => WebhookDeliveryError::TlsError,
            default => WebhookDeliveryError::ConnectionError,
        };
    }

    /** At most 2 KB of valid UTF-8, without control characters or secrets. */
    private function excerpt(string $body): ?string
    {
        return $this->excerpts->of($body, config()->integer('axispay.webhooks.response_excerpt_bytes'));
    }

    /** Seconds left of the total budget. */
    private function remaining(int|float $started, float $total): float
    {
        return $total - (hrtime(true) - $started) / 1e9;
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
