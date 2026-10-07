<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Modules\PaymentLinks\Enums\DisputeStatus;

/**
 * State of a dispute (plan 7.5 `disputes.status`, 16.2). `warning_closed` is
 * an early inquiry that closed without a chargeback. The link and the payment
 * only show the summary (DisputeStatus): see summary().
 */
enum DisputeState: string
{
    case NeedsResponse = 'needs_response';
    case UnderReview = 'under_review';
    case Won = 'won';
    case Lost = 'lost';
    case WarningClosed = 'warning_closed';

    public function isOpen(): bool
    {
        return $this === self::NeedsResponse || $this === self::UnderReview;
    }

    /**
     * What a dispute adds to the summary of its payment: open while it is
     * open, lost or won once closed. An inquiry that closed without a
     * chargeback counts as won: no money was lost.
     */
    public function summary(): DisputeStatus
    {
        return match ($this) {
            self::NeedsResponse, self::UnderReview => DisputeStatus::Open,
            self::Lost => DisputeStatus::Lost,
            self::Won, self::WarningClosed => DisputeStatus::Won,
        };
    }

    public function label(): string
    {
        return __('payments.dispute_state.'.$this->value);
    }
}
