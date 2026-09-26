<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Enums;

use App\Modules\ApiKeys\Http\Middleware\EnforceIdempotency;

/**
 * Whether an endpoint requires `Idempotency-Key` (plan 10.3); the
 * EnforceIdempotency middleware parameter.
 */
enum IdempotencyRequirement: string
{
    case Required = 'required';
    case Optional = 'optional';

    /** `EnforceIdempotency:required` style middleware string. */
    public function middleware(): string
    {
        return EnforceIdempotency::class.':'.$this->value;
    }
}
