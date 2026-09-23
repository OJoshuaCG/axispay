<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use DomainException;

final class CannotDeactivateUserException extends DomainException
{
    public static function self(): self
    {
        return new self('You cannot deactivate your own account.');
    }

    public static function morePrivileged(): self
    {
        return new self('You can only deactivate or reactivate users whose permissions you hold yourself.');
    }

    public static function lastOwner(): self
    {
        return new self('The last active owner of a tenant cannot be deactivated.');
    }
}
