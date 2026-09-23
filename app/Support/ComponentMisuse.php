<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The ONE policy for invalid Blade component input (unknown variant, size,
 * status or icon, non-numeric amount, missing accessible name):
 *
 *   - local and testing: throw, so the mistake is fixed before it ships;
 *   - everywhere else:   log a warning and let the component render its
 *                        documented fallback, so a typo never breaks a page.
 *
 * See docs/frontend/components.md ("Invalid input policy").
 */
final class ComponentMisuse
{
    /**
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidArgumentException in local and testing environments
     */
    public static function report(string $message, array $context = [], ?Throwable $previous = null): void
    {
        if (app()->environment(['local', 'testing'])) {
            throw new InvalidArgumentException($message, 0, $previous);
        }

        Log::warning($message, $context);
    }
}
