<?php

declare(strict_types=1);

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Every test boots the Laravel application (config is needed even by money
| and ID tests). Feature tests hit the real MariaDB configured in phpunit.xml.
|
*/

pest()->extend(TestCase::class)->in('Unit', 'Feature');

/**
 * Absolute URL on the public API host (ADR-027).
 */
function apiUrl(string $path): string
{
    $host = config('paylink.surfaces.api');

    return 'http://'.(is_string($host) ? $host : 'api.localhost').'/'.ltrim($path, '/');
}
