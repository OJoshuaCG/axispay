<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * State of a refund (plan 7.5 `refunds.status`, 16.1). Named a state, not a
 * status, because `PaymentLinks\Enums\RefundStatus` is the none / partial /
 * full summary of a link. A refund starts `pending` and the gateway moves it
 * to a final state; only a succeeded refund can still fail later (the card
 * network returns it), and a failed or canceled one never moves again.
 */
enum RefundState: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /**
     * A refund that holds money back from the payment: what is pending or
     * done cannot be refunded again (plan 16.1).
     */
    public function reservesAmount(): bool
    {
        return $this === self::Pending || $this === self::Succeeded;
    }

    /** Whether a refund in this state may move to `$next` (a stale or repeated report never moves it back). */
    public function canMoveTo(self $next): bool
    {
        return match ($this) {
            self::Pending => $next !== self::Pending,
            self::Succeeded => $next === self::Failed,
            self::Failed, self::Canceled => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }

    public function label(): string
    {
        return __('payments.refund_state.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Pending => 'warning',
            self::Failed, self::Canceled => 'danger',
        };
    }
}
