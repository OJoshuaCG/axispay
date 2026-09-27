<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * Extension point of ADR-0050 step 4: asks the merchant whether an
 * AUTHORIZED payment may be captured (plan 15.8). Phase 4 binds
 * NoPrePaymentValidation (always "not configured"); Phase 5 binds the signed
 * synchronous callback with its timeout and fail_closed / fail_open policy.
 *
 * Contract for implementations (rules.md rule 7b):
 *  - called with NO row lock and NO open transaction (the caller asserts it);
 *  - must answer within 5 seconds in total;
 *  - the caller re-locks and re-verifies the attempt and the link after it.
 */
interface PrePaymentValidator
{
    public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision;
}
