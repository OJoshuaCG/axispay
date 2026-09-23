<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use DomainException;

final class TenantCloseNotConfirmedException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Closing a tenant requires retyping its display name.');
    }
}
