<?php

declare(strict_types=1);

namespace App\Modules\PayerFields\Enums;

/** Plan 19.1: each payer field is hidden, optional or required. */
enum PayerFieldRequirement: string
{
    case Hidden = 'hidden';
    case Optional = 'optional';
    case Required = 'required';

    public function label(): string
    {
        return __('payment_links.payer_requirement.'.$this->value);
    }
}
