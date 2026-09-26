<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

use App\Modules\Gateways\Enums\ApiKeyRejection;
use RuntimeException;
use Throwable;

/**
 * A pair of merchant keys failed a check of plan 12.3.3. `details` carries
 * safe context only (permission names, a country code), never key material.
 */
final class ApiKeyValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $details
     */
    public function __construct(
        public readonly ApiKeyRejection $rejection,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct('API key validation failed: '.$rejection->value, 0, $previous);
    }

    public function userMessage(): string
    {
        return $this->rejection->message(['details' => implode(', ', $this->details)]);
    }
}
