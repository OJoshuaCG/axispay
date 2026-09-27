<?php

declare(strict_types=1);

namespace App\Modules\PayerFields\Exceptions;

use RuntimeException;

/**
 * Payer fields that do not pass validation. `errors` maps each input name
 * (`email`, `billing_address.postal_code`...) to a translated message.
 * Values are never included.
 */
final class InvalidPayerDataException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The payer data is invalid.');
    }
}
