<?php

declare(strict_types=1);

namespace App\Modules\Fx\Enums;

/**
 * Currency conversion mode (plan 7.3, 7.5, 10.5): chosen per link, with a
 * tenant default. Stored on links since Phase 3; the conversion itself
 * arrives with the rest of the Fx module in Phase 6.
 */
enum FxMode: string
{
    case None = 'none';
    case BanxicoFix = 'banxico_fix';
    case Fixed = 'fixed';

    public function label(): string
    {
        return __('payment_links.fx_mode.'.$this->value);
    }
}
