<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Why a refund is made (plan 7.5 `refunds.reason`), as the integrator says it.
 * Optional in the API: `other` when not given.
 */
enum RefundReason: string
{
    case RequestedByCustomer = 'requested_by_customer';
    case Duplicate = 'duplicate';
    case Fraudulent = 'fraudulent';
    case Other = 'other';

    /**
     * What a gateway is told: the reasons card networks know. `other` is not
     * one of them, so nothing is sent.
     */
    public function forGateway(): ?string
    {
        return $this === self::Other ? null : $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }

    public function label(): string
    {
        return __('payments.refund_reason.'.$this->value);
    }
}
