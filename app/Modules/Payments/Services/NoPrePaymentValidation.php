<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Models\PaymentAttempt;

/** Phase 4 default: no merchant validation exists yet, capture right away. */
final class NoPrePaymentValidation implements PrePaymentValidator
{
    public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
    {
        return PrePaymentDecision::notConfigured();
    }
}
