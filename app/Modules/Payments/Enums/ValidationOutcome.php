<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * The merchant's decision on an authorized attempt (ADR-0050 step 4), kept on
 * the attempt the moment it is known, so it is never asked twice and a
 * rejected authorization is never captured by a later retry, webhook or
 * reconciliation.
 */
enum ValidationOutcome: string
{
    case NotConfigured = 'not_configured';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function allowsCapture(): bool
    {
        return $this !== self::Rejected;
    }
}
