<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Enums\ValidationOutcome;

/**
 * The merchant's answer before capture (ADR-024, ADR-0050 step 4, plan
 * 15.8.4). `notConfigured` means no validation applies to the link: the flow
 * goes straight to capture. A rejection voids the authorization; its
 * `payerMessage` (already sanitized) is shown to the payer, and
 * `cancelLink` cancels the link once the authorization is voided. A failed
 * call follows the endpoint's policy (plan 15.8.5): `failedOpen()` captures,
 * `failedClosed()` voids and the payer sees the generic message.
 */
final readonly class PrePaymentDecision
{
    private function __construct(
        public bool $approved,
        public bool $notConfigured,
        public ?string $payerMessage,
        public bool $cancelLink,
        public bool $failed = false,
    ) {}

    public function outcome(): ValidationOutcome
    {
        return match (true) {
            $this->notConfigured => ValidationOutcome::NotConfigured,
            $this->failed => $this->approved ? ValidationOutcome::FailedOpen : ValidationOutcome::FailedClosed,
            $this->approved => ValidationOutcome::Approved,
            default => ValidationOutcome::Rejected,
        };
    }

    public static function notConfigured(): self
    {
        return new self(true, true, null, false);
    }

    public static function approve(): self
    {
        return new self(true, false, null, false);
    }

    public static function reject(?string $payerMessage = null, bool $cancelLink = false): self
    {
        return new self(false, false, $payerMessage, $cancelLink);
    }

    /** The call failed and the endpoint's policy is `fail_open`: capture anyway. */
    public static function failedOpen(): self
    {
        return new self(true, false, null, false, true);
    }

    /** The call failed and the endpoint's policy is `fail_closed`: no charge. */
    public static function failedClosed(): self
    {
        return new self(false, false, null, false, true);
    }
}
