<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Data;

use App\Modules\Fx\Models\FxQuote;
use App\Modules\PaymentLinks\Data\LineItem;

/**
 * What the payer is shown to confirm a currency conversion (plan 13.5): the
 * quote that will be charged. The page renders it; the amount is exactly the
 * quote's, never recomputed. `lines` is the merchant's breakdown converted to
 * MXN so that it adds up to the quote's amount (ADR-0064); empty when the
 * link has none or it cannot be converted honestly.
 */
final readonly class CurrencyConfirmation
{
    /**
     * @param  list<LineItem>  $lines  the link's line items in MXN
     */
    public function __construct(public FxQuote $quote, public array $lines = []) {}
}
