<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Enums;

/**
 * What a failed pre-payment validation does (plan 15.8.5): `fail_closed`
 * (default) voids the authorization, `fail_open` captures it and records it.
 */
enum ValidationFailurePolicy: string
{
    case FailClosed = 'fail_closed';
    case FailOpen = 'fail_open';

    public function charges(): bool
    {
        return $this === self::FailOpen;
    }

    public function label(): string
    {
        return __('webhooks.validation.policy.'.$this->value.'.label');
    }

    /** The consequences, in plain words, for the panel (plan 15.8.1). */
    public function explanation(): string
    {
        return __('webhooks.validation.policy.'.$this->value.'.explanation');
    }
}
