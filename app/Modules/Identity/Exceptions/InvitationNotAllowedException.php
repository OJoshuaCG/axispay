<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use DomainException;

final class InvitationNotAllowedException extends DomainException
{
    /**
     * @param  'role_not_grantable'|'throttled'  $reason
     */
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function roleNotGrantable(): self
    {
        return new self('role_not_grantable', 'You can only invite with a role whose permissions you hold yourself.');
    }

    public static function throttled(): self
    {
        return new self('throttled', 'Too many invitations for this tenant. Try again later.');
    }
}
