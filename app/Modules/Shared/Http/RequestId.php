<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http;

use App\Modules\Shared\Ids\Ulid;
use Illuminate\Support\Facades\Context;

/**
 * Access to the current request ID (plan sections 10.1 and 24.1).
 *
 * The ID lives in Laravel's Context, so it is attached to every log record
 * and propagated automatically to queued jobs dispatched during the request.
 */
final class RequestId
{
    public const string CONTEXT_KEY = 'request_id';

    public const string RESPONSE_HEADER = 'Request-Id';

    public const string INCOMING_HEADER = 'X-Request-Id';

    /** Safe charset and bounded length for client-supplied IDs. */
    private const string SAFE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D';

    private function __construct() {}

    public static function generate(): string
    {
        return 'req_'.Ulid::generate();
    }

    public static function isSafe(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SAFE_PATTERN, $value) === 1;
    }

    public static function current(): string
    {
        $current = Context::get(self::CONTEXT_KEY);

        if (is_string($current) && $current !== '') {
            return $current;
        }

        $generated = self::generate();
        Context::add(self::CONTEXT_KEY, $generated);

        return $generated;
    }
}
