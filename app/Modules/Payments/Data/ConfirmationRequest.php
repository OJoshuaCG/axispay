<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Shared\Money\Money;
use SensitiveParameter;

/**
 * The payer's confirmation of an attempt (ConfirmAttemptPayment): the
 * gateway's confirmation token, the amount to charge now, where the gateway
 * sends the payer back after 3D Secure, the receipt e-mail (only when the
 * tenant sends gateway receipts), the payer's IP (declines, plan 11.7) and
 * the time left in the payer's request (CallBudget).
 */
final readonly class ConfirmationRequest
{
    public function __construct(
        #[SensitiveParameter] public string $confirmationToken,
        public Money $amount,
        public string $returnUrl,
        #[SensitiveParameter] public ?string $receiptEmail,
        public ?string $clientIp,
        public ?CallBudget $budget = null,
    ) {}
}
