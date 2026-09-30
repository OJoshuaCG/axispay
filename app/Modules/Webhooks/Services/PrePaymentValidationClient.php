<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Shared\Database\Transactions;
use App\Modules\Webhooks\Data\ValidationHttpResult;
use App\Modules\Webhooks\Enums\ValidationFailureKind;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Sends one signed pre-payment validation request (plan 15.8.3, 15.8.5):
 *
 *  - the SSRF guard runs right before the call and the client connects only
 *    to the addresses it validated (CURLOPT_RESOLVE); redirects are never
 *    followed;
 *  - Standard Webhooks headers (`webhook-id` = the call's `val_...`, signed
 *    with the current secret and, for 24 hours after a rotation, the
 *    previous one) plus `x-axispay-kind: pre_payment_validation`;
 *  - 2 seconds to connect and 5 seconds IN TOTAL, the DNS lookup of the
 *    SSRF guard and the retry included: one
 *    immediate retry, only when the connection could not be opened (nothing
 *    was sent); never after a read timeout, the merchant may have processed
 *    the request;
 *  - at most 4 KB of the answer are read.
 *
 * Never called inside a database transaction or while holding a row lock
 * (rules.md rule 7b): it throws. The secret and the body never reach logs.
 */
final readonly class PrePaymentValidationClient
{
    public const string KIND_HEADER = 'x-axispay-kind';

    public const string KIND = 'pre_payment_validation';

    public function __construct(
        private DestinationGuard $guard,
        private WebhookSigner $signer,
    ) {}

    public function send(ValidationEndpoint $endpoint, string $webhookId, #[SensitiveParameter] string $body): ValidationHttpResult
    {
        if (Transactions::open()) {
            throw new LogicException('A pre-payment validation is never sent inside a database transaction or under a row lock (rules.md rule 7b).');
        }

        $started = hrtime(true);
        $total = (float) max(1, config()->integer('axispay.pre_payment_validation.timeout_seconds'));
        $connect = (float) max(1, config()->integer('axispay.pre_payment_validation.connect_timeout_seconds'));

        try {
            $destination = $this->guard->inspect($endpoint->url, $endpoint->livemode, DestinationGuard::clock() + $total);
        } catch (UnsafeDestinationException) {
            // A lookup cut short by the budget is a timeout, not a blocked URL.
            return $total - self::elapsedMs($started) / 1000 <= 0.05
                ? ValidationHttpResult::failed(ValidationFailureKind::Timeout, self::elapsedMs($started))
                : ValidationHttpResult::failed(ValidationFailureKind::BlockedDestination, self::elapsedMs($started));
        }

        $timestamp = CarbonImmutable::now()->getTimestamp();
        $headers = [
            'webhook-id' => $webhookId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => $this->signer->header($endpoint->signingSecrets(), $webhookId, $timestamp, $body),
            self::KIND_HEADER => self::KIND,
            'user-agent' => config()->string('axispay.pre_payment_validation.user_agent'),
            'accept' => 'application/json',
        ];
        $maxBytes = ValidationResponseParser::maxBytes();
        $retried = false;

        while (true) {
            $remaining = $total - self::elapsedMs($started) / 1000;

            if ($remaining <= 0.05) {
                return ValidationHttpResult::failed(ValidationFailureKind::Timeout, self::elapsedMs($started), $retried);
            }

            $headerStatus = null;
            $tooLarge = false;

            try {
                $response = Http::withHeaders($headers)
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

                                throw new RuntimeException('Validation response too large.');
                            }
                        },
                    ])
                    ->post($destination->url);
            } catch (Throwable $e) {
                if ($tooLarge) {
                    return new ValidationHttpResult(null, is_int($headerStatus) ? $headerStatus : null, '', null, true, self::elapsedMs($started), $retried);
                }

                if (! $retried && CurlFailure::isTransport($e) && CurlFailure::failedBeforeSending($e)) {
                    $retried = true;

                    continue;
                }

                return ValidationHttpResult::failed(self::classify($e), self::elapsedMs($started), $retried);
            }

            return self::answer($response, $maxBytes, self::elapsedMs($started), $retried);
        }
    }

    private static function answer(Response $response, int $maxBytes, int $durationMs, bool $retried): ValidationHttpResult
    {
        $body = $response->body();
        $tooLarge = strlen($body) > $maxBytes;
        $contentType = $response->header('Content-Type');

        return new ValidationHttpResult(
            null,
            $response->status(),
            $tooLarge ? substr($body, 0, $maxBytes) : $body,
            $contentType !== '' ? $contentType : null,
            $tooLarge,
            $durationMs,
            $retried,
        );
    }

    private static function classify(Throwable $e): ValidationFailureKind
    {
        if (! CurlFailure::isTransport($e)) {
            return ValidationFailureKind::ConnectionError;
        }

        return match (true) {
            CurlFailure::isTimeout($e) && ! CurlFailure::failedBeforeSending($e) => ValidationFailureKind::Timeout,
            CurlFailure::isTls($e) => ValidationFailureKind::TlsError,
            default => ValidationFailureKind::ConnectionError,
        };
    }

    private static function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
