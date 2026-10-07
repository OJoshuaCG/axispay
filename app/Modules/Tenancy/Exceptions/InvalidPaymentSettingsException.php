<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * The payment settings do not pass the platform limits. `errors` maps the
 * form field to a translated message, so the panel shows each next to its
 * field.
 */
final class InvalidPaymentSettingsException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors  field => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The payment settings are invalid.');
    }
}
