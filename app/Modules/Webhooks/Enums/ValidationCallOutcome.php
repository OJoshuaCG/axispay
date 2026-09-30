<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/** The result of one pre-payment validation call (plan 7.6 `validation_calls.outcome`). */
enum ValidationCallOutcome: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return __('webhooks.validation.outcome.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected => 'warning',
            self::Failed => 'danger',
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
