<?php

declare(strict_types=1);

namespace App\Modules\Shared\Money\Exceptions;

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;

/**
 * An amount or currency failed the parsing rules of plan section 8.2. Carries
 * the API error code so the HTTP layer renders it without translation.
 */
final class InvalidAmountException extends ApiException
{
    public static function because(ApiErrorCode $code, string $message, string $param): self
    {
        return new self($code, $message, $param);
    }
}
