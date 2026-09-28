<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Names the database concurrency error behind an exception, so callers can
 * retry it and log it for what it is:
 *
 *  - `record_changed`: MariaDB's snapshot isolation (11.8 default) refused
 *    to lock a row that another transaction changed after this one's read
 *    snapshot (ER_CHECKREAD, 1020);
 *  - `deadlock` (1213) and `lock_wait_timeout` (1205).
 */
final class ConcurrencyErrors
{
    public const string RECORD_CHANGED = 'record_changed';

    public const string DEADLOCK = 'deadlock';

    public const string LOCK_WAIT_TIMEOUT = 'lock_wait_timeout';

    private const int ER_CHECKREAD = 1020;

    private const int ER_LOCK_DEADLOCK = 1213;

    private const int ER_LOCK_WAIT_TIMEOUT = 1205;

    public static function kind(Throwable $e): ?string
    {
        $code = $e instanceof QueryException && is_array($e->errorInfo) ? ($e->errorInfo[1] ?? null) : null;
        $message = $e->getMessage();

        return match (true) {
            $code === self::ER_CHECKREAD || str_contains($message, 'Record has changed since last read') => self::RECORD_CHANGED,
            $code === self::ER_LOCK_DEADLOCK || str_contains($message, 'Deadlock found') => self::DEADLOCK,
            $code === self::ER_LOCK_WAIT_TIMEOUT || str_contains($message, 'Lock wait timeout exceeded') => self::LOCK_WAIT_TIMEOUT,
            default => null,
        };
    }

    /** A short random pause (5-20 ms) before retrying, so colliding processes drift apart. */
    public static function jitter(): void
    {
        usleep(random_int(5_000, 20_000));
    }
}
