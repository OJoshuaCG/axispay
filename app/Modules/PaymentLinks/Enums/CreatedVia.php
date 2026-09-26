<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Enums;

/** Where a link was created (plan 7.5 `created_via`). */
enum CreatedVia: string
{
    case Api = 'api';
    case Panel = 'panel';

    public function label(): string
    {
        return __('payment_links.created_via.'.$this->value);
    }
}
