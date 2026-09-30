<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Status of a webhook endpoint (plan 7.6). Only `enabled` endpoints receive
 * events; `disabled_by_failures` is set after 5 days of continuous failures
 * (plan 15.6).
 */
enum WebhookEndpointStatus: string
{
    case Enabled = 'enabled';
    case DisabledByUser = 'disabled_by_user';
    case DisabledByFailures = 'disabled_by_failures';

    public function isEnabled(): bool
    {
        return $this === self::Enabled;
    }

    public function label(): string
    {
        return __('webhooks.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Enabled => 'success',
            self::DisabledByUser => 'gray',
            self::DisabledByFailures => 'danger',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Enabled => Heroicon::OutlinedCheckCircle,
            self::DisabledByUser => Heroicon::OutlinedPauseCircle,
            self::DisabledByFailures => Heroicon::OutlinedExclamationTriangle,
        };
    }
}
