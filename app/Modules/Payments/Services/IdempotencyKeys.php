<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

/**
 * Idempotency keys of the gateway calls of an attempt (plan 11.4, rules.md
 * rule 5): stable per attempt and operation, so a retry (network error,
 * double click, webhook and payer at once) repeats the same key and the
 * gateway performs the operation once.
 */
final class IdempotencyKeys
{
    public static function create(string $attemptId): string
    {
        return "axispay:create_pi:{$attemptId}";
    }

    public static function confirm(string $attemptId, string $confirmationToken): string
    {
        return "axispay:confirm:{$attemptId}:{$confirmationToken}";
    }

    public static function capture(string $attemptId): string
    {
        return "axispay:capture:{$attemptId}";
    }

    public static function cancel(string $attemptId): string
    {
        return "axispay:cancel:{$attemptId}";
    }
}
