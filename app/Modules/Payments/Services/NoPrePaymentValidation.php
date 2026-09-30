<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Contracts\PrePaymentValidator;
use App\Modules\Payments\Data\PrePaymentDecision;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * No merchant validation applies (the link was created without
 * `pre_payment_validation`): capture right away. HttpPrePaymentValidator
 * delegates to it for those links (plan 15.8.1).
 */
final class NoPrePaymentValidation implements PrePaymentValidator
{
    public function decide(PaymentLink $link, PaymentAttempt $attempt): PrePaymentDecision
    {
        return PrePaymentDecision::notConfigured();
    }
}
