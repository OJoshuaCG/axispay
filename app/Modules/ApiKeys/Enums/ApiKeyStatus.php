<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Enums;

use Filament\Support\Icons\Heroicon;

/** Whether a key can still authenticate (plan 10.2). */
enum ApiKeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return __('api_keys.status.'.$this->value);
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Active => Heroicon::OutlinedCheckCircle,
            self::Revoked => Heroicon::OutlinedNoSymbol,
            self::Expired => Heroicon::OutlinedCalendar,
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

    /** Filament color of the status badge. */
    public function color(): string
    {
        return $this === self::Active ? 'success' : 'gray';
    }
}
