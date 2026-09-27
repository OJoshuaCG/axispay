<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** What happened when an authorized attempt was completed (ADR-0050 steps 4-5). */
enum CaptureOutcome: string
{
    /** Captured (or the gateway is still processing the capture). */
    case Captured = 'captured';
    /** The merchant rejected it: the authorization was voided, nothing charged. */
    case Rejected = 'rejected';
    /** Nothing done now: another process holds the attempt or the gateway is unavailable; retried later. */
    case Pending = 'pending';
    /** The attempt is not authorized (any more): nothing to capture. */
    case NotAuthorized = 'not_authorized';
}
