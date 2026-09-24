<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Errors;

use Illuminate\Http\Request;

/**
 * Tells whether a request targets the public API surface, which is served on
 * its own host (ADR-027, plan section 4.1). Only those requests get the API
 * error envelope; web and panel errors keep Laravel's default rendering.
 */
final class ApiSurface
{
    private function __construct() {}

    public static function host(): string
    {
        $host = config('axispay.surfaces.api');

        return is_string($host) ? strtolower($host) : '';
    }

    public static function matches(Request $request): bool
    {
        $host = self::host();

        return $host !== '' && strtolower($request->getHost()) === $host;
    }
}
