<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database;

use App\Modules\Shared\Ids\Ulid;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * ULID primary key in canonical uppercase form (ADR-020).
 *
 * Laravel's HasUlids generates lowercase ULIDs; with a CHAR(26) ascii_bin
 * column that would make the stored value differ from the canonical form the
 * API decodes, so generation and validation are overridden here. Pair it with
 * `$table->ulidAscii('id')->primary()` in the migration.
 */
trait HasUlidPrimaryKey
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return Ulid::generate();
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return Ulid::isValid($value);
    }
}
