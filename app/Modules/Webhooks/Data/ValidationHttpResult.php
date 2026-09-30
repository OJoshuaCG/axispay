<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Enums\ValidationFailureKind;

/**
 * What came back from one pre-payment validation request
 * (PrePaymentValidationClient): either a transport failure (timeout,
 * connection, TLS, blocked destination) or an HTTP answer, whose body is at
 * most `max_response_bytes` (`tooLarge` when the merchant sent more).
 */
final readonly class ValidationHttpResult
{
    public function __construct(
        public ?ValidationFailureKind $transportFailure,
        public ?int $status,
        public string $body,
        public ?string $contentType,
        public bool $tooLarge,
        public int $durationMs,
        public bool $retried,
    ) {}

    public static function failed(ValidationFailureKind $kind, int $durationMs, bool $retried = false, ?int $status = null): self
    {
        return new self($kind, $status, '', null, false, $durationMs, $retried);
    }
}
