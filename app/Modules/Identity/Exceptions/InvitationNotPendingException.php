<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use DomainException;

final class InvitationNotPendingException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This invitation is no longer valid.');
    }
}
