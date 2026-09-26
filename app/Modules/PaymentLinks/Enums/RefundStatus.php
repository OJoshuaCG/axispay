<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Enums;

/** Refund summary of a link (plan 7.5); maintained from Phase 7. */
enum RefundStatus: string
{
    case None = 'none';
    case Partial = 'partial';
    case Full = 'full';

    public function label(): string
    {
        return __('payment_links.refund_status.'.$this->value);
    }
}
