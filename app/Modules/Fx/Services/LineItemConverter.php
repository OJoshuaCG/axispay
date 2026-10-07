<?php

declare(strict_types=1);

namespace App\Modules\Fx\Services;

use App\Modules\PaymentLinks\Data\LineItem;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Shared\Money\Money;

/**
 * Converts the merchant's line items with the rate of a quote (ADR-0064),
 * so the hosted page can show the breakdown of the MXN amount: each line is
 * converted on its own (FxConverter, round_half_up once), and the line
 * flagged `absorbs_rounding` takes the residual, so the converted lines add
 * up to exactly the amount charged (12.30 USD x 20 = 246.00 MXN).
 *
 * Returns null, and the page shows the total alone, when that cannot be done
 * honestly: not exactly one flagged line, or a residual that would leave the
 * flagged line at zero or below (many tiny lines that all rounded up).
 */
final readonly class LineItemConverter
{
    private const int MINIMUM_RESIDUAL_MINOR = 1;

    public function __construct(private FxConverter $converter = new FxConverter) {}

    /**
     * @param  list<LineItem>  $items  in the link's currency, adding up to the link amount
     * @param  Money  $convertedTotal  the amount charged in MXN (the quote's)
     * @param  ExchangeRate  $effectiveRate  the quote's effective rate
     * @return list<LineItem>|null the same lines in MXN, in the same order
     */
    public function convert(array $items, Money $convertedTotal, ExchangeRate $effectiveRate): ?array
    {
        $absorbingIndexes = array_keys(array_filter($items, static fn (LineItem $item): bool => $item->absorbsRounding));

        if (count($absorbingIndexes) !== 1) {
            return null;
        }

        $absorbingIndex = $absorbingIndexes[0];
        $convertedMinorByIndex = [];
        $convertedOthersMinor = 0;

        foreach ($items as $index => $item) {
            if ($index === $absorbingIndex) {
                continue;
            }

            $convertedMinorByIndex[$index] = $this->converter->apply($item->amount, $effectiveRate)->minorAmount;
            $convertedOthersMinor += $convertedMinorByIndex[$index];
        }

        $residualMinor = $convertedTotal->minorAmount - $convertedOthersMinor;

        if ($residualMinor < self::MINIMUM_RESIDUAL_MINOR) {
            return null;
        }

        $convertedMinorByIndex[$absorbingIndex] = $residualMinor;
        $converted = [];

        foreach ($items as $index => $item) {
            $converted[] = new LineItem($item->label, Money::ofMinor($convertedMinorByIndex[$index], $convertedTotal->currency), $item->absorbsRounding);
        }

        return $converted;
    }
}
