<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database;

/**
 * Writes Eloquent datetimes with microseconds, matching the DATETIME(6)
 * columns of the schema convention (plan 6.8). Without it, Eloquent formats
 * with `Y-m-d H:i:s` and the stored fraction is always zero.
 */
trait UsesMicrosecondDates
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }
}
