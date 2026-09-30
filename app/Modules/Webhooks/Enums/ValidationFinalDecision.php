<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/** Whether the authorization was captured after the validation (plan 7.6). */
enum ValidationFinalDecision: string
{
    case Charge = 'charge';
    case Block = 'block';

    public function label(): string
    {
        return __('webhooks.validation.final_decision.'.$this->value);
    }
}
