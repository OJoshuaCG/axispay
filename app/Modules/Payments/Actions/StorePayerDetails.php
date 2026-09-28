<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\PayerFields\Data\PayerData;
use App\Modules\Payments\Models\PayerDetails;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * Stores the payer's data of an attempt (plan 19.2): encrypted, one row per
 * attempt (a new try of the same attempt replaces it), purged after the
 * retention period. Nothing is stored when the link collects no payer data.
 */
final readonly class StorePayerDetails
{
    public function handle(PaymentAttempt $attempt, PayerData $payer): void
    {
        if ($payer->isEmpty()) {
            return;
        }

        $details = PayerDetails::query()->where('payment_attempt_id', $attempt->id)->first() ?? new PayerDetails;
        $details->forceFill([
            'payment_attempt_id' => $attempt->id,
            'data' => $payer->toArray(),
            'purge_after' => now()->addMonths(max(1, config()->integer('axispay.payments.payer_retention_months'))),
        ])->save();
    }
}
