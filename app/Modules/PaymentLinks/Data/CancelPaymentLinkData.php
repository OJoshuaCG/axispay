<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Data;

use App\Modules\PaymentLinks\Enums\CancelReason;

/**
 * Input of CancelPaymentLink: an optional reason, already trimmed and within
 * CancelPaymentLinkInputParser::REASON_MAX characters.
 */
final readonly class CancelPaymentLinkData
{
    public function __construct(public ?string $reason = null) {}

    public static function because(CancelReason $reason): self
    {
        return new self($reason->value);
    }
}
