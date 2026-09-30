<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Status of one delivery attempt (plan 7.6). `failed` is a failed attempt
 * (a retry may follow, see `next_retry_at`); `abandoned` is the last attempt
 * of the automatic schedule, a blocked destination (never retried, plan
 * 15.7) or an attempt dropped because its endpoint was disabled.
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return __('webhooks.delivery_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Succeeded => 'success',
            self::Failed => 'warning',
            self::Abandoned => 'danger',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Pending => Heroicon::OutlinedClock,
            self::Succeeded => Heroicon::OutlinedCheckCircle,
            self::Failed => Heroicon::OutlinedArrowPath,
            self::Abandoned => Heroicon::OutlinedXCircle,
        };
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
