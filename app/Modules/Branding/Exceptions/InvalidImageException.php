<?php

declare(strict_types=1);

namespace App\Modules\Branding\Exceptions;

use App\Modules\Branding\Enums\ImageRejection;
use RuntimeException;

final class InvalidImageException extends RuntimeException
{
    public function __construct(public readonly ImageRejection $rejection)
    {
        parent::__construct('Invalid image: '.$rejection->value);
    }
}
