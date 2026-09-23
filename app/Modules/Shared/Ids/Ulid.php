<?php

declare(strict_types=1);

namespace App\Modules\Shared\Ids;

use Illuminate\Support\Str;

/**
 * Canonical ULID handling (ADR-020). Stored as CHAR(26) ascii_bin, so the
 * canonical form is the uppercase Crockford base32 string; lowercase or
 * otherwise non-canonical input is rejected instead of normalized, because a
 * binary collation compares it as a different value.
 */
final class Ulid
{
    /** First char 0-7 keeps the 128-bit range; Crockford base32 excludes I, L, O, U. */
    private const string PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';

    private function __construct() {}

    public static function generate(): string
    {
        return strtoupper((string) Str::ulid());
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
