<?php

declare(strict_types=1);

namespace App\Modules\Identity\Data;

use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use InvalidArgumentException;

/**
 * An operator-initiated account recovery (ADR-0041): the account, why it is
 * being recovered and where the request came from. The reason is stored in
 * the audit entry, so it must never contain a secret.
 */
final readonly class AccountRecoveryRequest
{
    public const int MIN_REASON_LENGTH = 10;

    public const int MAX_REASON_LENGTH = 500;

    public string $reason;

    public function __construct(
        public User|PlatformAdmin $account,
        string $reason,
        public string $source = 'cli',
    ) {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < self::MIN_REASON_LENGTH || $length > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The reason must be between %d and %d characters.',
                self::MIN_REASON_LENGTH,
                self::MAX_REASON_LENGTH,
            ));
        }

        $this->reason = $reason;
    }
}
