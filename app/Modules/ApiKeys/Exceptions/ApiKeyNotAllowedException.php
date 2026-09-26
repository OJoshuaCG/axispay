<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Exceptions;

use App\Modules\ApiKeys\Enums\ApiKeyRefusal;
use App\Modules\Shared\Contracts\UserFacingError;
use RuntimeException;

/**
 * An API key cannot be created as requested (panel only; never an API
 * answer).
 */
final class ApiKeyNotAllowedException extends RuntimeException implements UserFacingError
{
    public function __construct(public readonly ApiKeyRefusal $reason)
    {
        parent::__construct("API key operation not allowed: {$reason->value}.");
    }

    public function userMessage(): string
    {
        return $this->reason->message();
    }
}
