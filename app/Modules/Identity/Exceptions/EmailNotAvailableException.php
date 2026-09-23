<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use DomainException;

final class EmailNotAvailableException extends DomainException
{
    public function __construct()
    {
        parent::__construct('This e-mail address cannot be used.');
    }
}
