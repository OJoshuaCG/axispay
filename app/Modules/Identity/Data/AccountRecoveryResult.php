<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

/**
 * Outcome of an account recovery Action. `sessionsRevoked` is null when the
 * session driver cannot enumerate sessions by account (only `database` can).
 */
final readonly class AccountRecoveryResult
{
    public function __construct(
        public bool $changed,
        public ?int $sessionsRevoked = null,
    ) {}

    public static function unchanged(): self
    {
        return new self(false);
    }
}
