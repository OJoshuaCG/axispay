<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Link lifecycle (plan 9.1). Transitions only through
 * PaymentLinkStateMachine. `paid` is terminal; refunds and disputes live in
 * `refund_status` / `dispute_status`.
 */
enum PaymentLinkStatus: string
{
    case Active = 'active';
    case Processing = 'processing';
    case Paid = 'paid';
    case Expired = 'expired';
    case Canceled = 'canceled';

    public function label(): string
    {
        return __('payment_links.status.'.$this->value);
    }

    /** Filament color of the status badge (semantic palette names). */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Processing => 'warning',
            self::Paid => 'success',
            self::Expired, self::Canceled => 'gray',
        };
    }

    /** Icon shown with the badge, so color is never the only signal. */
    public function icon(): Heroicon
    {
        return match ($this) {
            self::Active => Heroicon::OutlinedClock,
            self::Processing => Heroicon::OutlinedArrowPath,
            self::Paid => Heroicon::OutlinedCheckCircle,
            self::Expired => Heroicon::OutlinedCalendar,
            self::Canceled => Heroicon::OutlinedNoSymbol,
        };
    }

    /** Whether the link can still be shared and paid (plan 9.1). */
    public function isShareable(): bool
    {
        return $this === self::Active || $this === self::Processing;
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
