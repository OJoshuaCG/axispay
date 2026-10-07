<?php

declare(strict_types=1);

namespace App\Modules\Fx\Enums;

/**
 * Currency conversion mode (plan 7.3, 7.5, 10.5): chosen per link, with a
 * tenant default. Stored on links since Phase 3; ConversionPolicy and
 * FxQuoter apply it (ADR-0063).
 */
enum FxMode: string
{
    case None = 'none';
    case BanxicoFix = 'banxico_fix';
    case Fixed = 'fixed';

    /** Whether this mode converts at all (`none` never does). */
    public function converts(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return __('payment_links.fx_mode.'.$this->value);
    }
}
