<?php

declare(strict_types=1);

namespace App\Modules\Shared\Ids;

/**
 * API-visible resource types and their ID prefix (ADR-020, plan 10.5-10.8 and
 * 15.8.3). Add a case when a new resource becomes public; never reuse or
 * change an existing prefix.
 */
enum ResourceType: string
{
    case PaymentLink = 'plink';
    case Payment = 'pay';
    case Refund = 're';
    case Event = 'evt';
    case ValidationCall = 'val';

    public function prefix(): string
    {
        return $this->value.'_';
    }
}
