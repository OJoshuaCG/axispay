<?php

declare(strict_types=1);

namespace App\Modules\Legal\Enums;

use App\Modules\Legal\Data\LegalDocumentData;

/** Why a legal document was refused (ADR-0056); the message names the field's rule. */
enum LegalDocumentRejection: string
{
    case EmptyBody = 'empty_body';
    case BodyTooLong = 'body_too_long';
    case InvalidUrl = 'invalid_url';

    /** The form field the rejection belongs to. */
    public function field(): string
    {
        return $this === self::InvalidUrl ? 'url' : 'body';
    }

    public function message(): string
    {
        return __('legal.errors.'.$this->value, [
            'max' => number_format(LegalDocumentData::MAX_BODY_LENGTH),
            'max_url' => LegalDocumentData::MAX_URL_LENGTH,
        ]);
    }
}
