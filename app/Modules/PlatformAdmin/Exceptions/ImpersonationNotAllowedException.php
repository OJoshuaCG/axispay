<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Exceptions;

use DomainException;

final class ImpersonationNotAllowedException extends DomainException
{
    public static function reasonRequired(): self
    {
        return new self('A reason is required to impersonate a user.');
    }

    public static function inactiveTarget(): self
    {
        return new self('Only active users of an accessible tenant can be impersonated.');
    }

    public static function invalidToken(): self
    {
        return new self('This impersonation link is invalid or has expired.');
    }
}
