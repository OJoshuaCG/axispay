<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Fx\Models\FxQuote;

/**
 * What the payer is shown to confirm a currency conversion (plan 13.5): the
 * quote that will be charged. The page renders it; the amount is exactly the
 * quote's, never recomputed.
 */
final readonly class CurrencyConfirmation
{
    public function __construct(public FxQuote $quote) {}
}
