<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Errors;

use RuntimeException;
use Throwable;

/**
 * Base exception for errors that map 1:1 to the public API error format
 * (plan section 10.4). Throw it (or a subclass) from Actions and the HTTP
 * layer; ApiErrorRenderer turns it into the JSON envelope.
 *
 * The message is shown to API clients, so it must never contain secrets,
 * PII or internal details.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    final public function __construct(
        public readonly ApiErrorCode $errorCode,
        ?string $message = null,
        public readonly ?string $param = null,
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $errorCode->defaultMessage(), 0, $previous);
    }

    public static function of(ApiErrorCode $errorCode, ?string $message = null, ?string $param = null): static
    {
        return new static($errorCode, $message, $param);
    }

    public function status(): int
    {
        return $this->errorCode->httpStatus();
    }
}
