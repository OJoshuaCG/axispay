<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * A sensitive action was attempted outside the re-authentication window.
 */
final class ReauthenticationRequiredException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('Confirm your password to continue.');
    }
}
