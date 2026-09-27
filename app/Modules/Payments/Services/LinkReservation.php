<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * A link is reserved (`processing`) while a confirmation works on its
 * attempt (ADR-0051). If that confirmation died (crash, killed worker), the
 * reservation is abandoned: the attempt is not under way at the gateway and
 * its lease expired. An abandoned reservation may be taken over by the next
 * payment, instead of waiting for the reconciliation.
 */
final class LinkReservation
{
    public static function isAbandoned(PaymentLink $link): bool
    {
        if ($link->status !== PaymentLinkStatus::Processing) {
            return false;
        }

        $attempt = PaymentAttempt::query()
            ->where('payment_link_id', $link->id)
            ->whereIn('status', PaymentAttemptStatus::activeValues())
            ->first();

        return $attempt !== null && ! $attempt->status->isInFlight() && ! $attempt->leaseHeld();
    }
}
