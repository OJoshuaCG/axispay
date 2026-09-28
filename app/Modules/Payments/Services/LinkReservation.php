<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
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
    /**
     * @param  PaymentAttempt|null  $attempt  the link's active attempt when the caller already read it
     */
    public static function isAbandoned(PaymentLink $link, ?PaymentAttempt $attempt = null): bool
    {
        if ($link->status !== PaymentLinkStatus::Processing) {
            return false;
        }

        if ($attempt === null || $attempt->status->isTerminal() || $attempt->payment_link_id !== $link->id) {
            $attempt = PaymentAttempt::query()->activeForLink($link->id)->first();
        }

        return $attempt !== null && ! $attempt->status->isInFlight() && ! $attempt->leaseHeld();
    }
}
