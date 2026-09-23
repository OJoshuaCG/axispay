<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Modules\Tenancy\Enums\TenantStatus;
use DomainException;

final class InvalidTenantStatusTransitionException extends DomainException
{
    public function __construct(public readonly TenantStatus $from, public readonly TenantStatus $to)
    {
        parent::__construct("A tenant cannot move from [{$from->value}] to [{$to->value}].");
    }
}
