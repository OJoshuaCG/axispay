<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Enums;

/** Dispute summary of a link (plan 7.5); maintained from Phase 7. */
enum DisputeStatus: string
{
    case None = 'none';
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return __('payment_links.dispute_status.'.$this->value);
    }
}
