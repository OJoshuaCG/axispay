<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment lifecycle statuses and their presentation.
 *
 * Presentation-only for now: this enum is the single lookup the UI uses to map
 * a status to a label, badge variant and icon (see <x-payment-status>). Domain
 * rules (allowed transitions, persistence) are intentionally not modelled yet.
 */
enum PaymentStatus: string
{
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Pending = 'pending';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Disputed = 'disputed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /**
     * Translated label shown to users (lang/{locale}/payments.php, key
     * payments.status.<value>), in the current app locale.
     */
    public function label(): string
    {
        return __('payments.status.'.$this->value);
    }

    /**
     * Variant of the <x-badge> atom: neutral | success | warning | error | info.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Captured => 'success',
            self::Authorized, self::Refunded, self::PartiallyRefunded => 'info',
            self::Pending, self::Disputed => 'warning',
            self::Failed => 'error',
            self::Canceled, self::Expired => 'neutral',
        };
    }

    /**
     * Heroicons name (micro set) rendered inside the badge.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Authorized => 'shield-check',
            self::Captured => 'check-circle',
            self::Pending => 'clock',
            self::Refunded => 'arrow-uturn-left',
            self::PartiallyRefunded => 'arrow-uturn-left',
            self::Disputed => 'exclamation-triangle',
            self::Failed => 'x-circle',
            self::Canceled => 'no-symbol',
            self::Expired => 'calendar',
        };
    }
}
