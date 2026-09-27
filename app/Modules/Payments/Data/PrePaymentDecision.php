<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

/**
 * The merchant's answer before capture (ADR-024, ADR-0050 step 4, plan
 * 15.8.4). `notConfigured` means no validation applies to the link: the flow
 * goes straight to capture. A rejection voids the authorization; its
 * `payerMessage` (already sanitized) is shown to the payer, and
 * `cancelLink` cancels the link afterwards (wired in Phase 5).
 */
final readonly class PrePaymentDecision
{
    private function __construct(
        public bool $approved,
        public bool $notConfigured,
        public ?string $payerMessage,
        public bool $cancelLink,
    ) {}

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
}
