<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** Where a refund was requested (plan 7.5 `refunds.origin`). */
enum RefundOrigin: string
{
    case Api = 'api';
    case Panel = 'panel';

    /** Made in the gateway's own dashboard and imported from its event (plan 16.1). */
    case ProviderDashboard = 'provider_dashboard';

    public function label(): string
    {
        return __('payments.refund_origin.'.$this->value);
    }
}
