<?php

declare(strict_types=1);

namespace App\Modules\Legal\Exceptions;

use App\Modules\Legal\Enums\LegalDocumentRejection;
use RuntimeException;

final class InvalidLegalDocumentException extends RuntimeException
{
    public function __construct(public readonly LegalDocumentRejection $rejection)
    {
        parent::__construct('Invalid legal document: '.$rejection->value);
    }
}
