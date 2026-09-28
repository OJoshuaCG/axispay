<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Gateways\Data\ProviderPayment;

/**
 * Result of ConfirmAttemptPayment: the gateway's answer to the confirmation
 * and that answer applied to the attempt and its link.
 */
final readonly class ConfirmedAttempt
{
    public function __construct(
        public ProviderPayment $payment,
        public AppliedPayment $applied,
    ) {}
}
