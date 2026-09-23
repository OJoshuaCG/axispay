<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Migration helpers for the column conventions of plan sections 6.7 and 7:
 * ULIDs, tokens, hashes, idempotency keys and provider IDs use a binary ASCII
 * collation (case-sensitive, compact). Business datetimes use Laravel's
 * built-in `$table->dateTime('x', 6)` and `$table->datetimes(6)` (DATETIME(6),
 * never TIMESTAMP).
 *
 * Usage:
 *   $table->ulidAscii('id')->primary();
 *   $table->foreignUlidAscii('tenant_id');
 *   $table->asciiString('idempotency_key', 255)->nullable();
 *   $table->asciiChar('key_hash', 64)->unique();
 *   $table->string('provider_payment_id', 255)->asciiBin();
 */
final class SchemaMacros
{
    private function __construct() {}

    public static function register(): void
    {
        Blueprint::macro('ulidAscii', function (string $column = 'id'): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 26)->charset('ascii')->collation('ascii_bin');
        });

        Blueprint::macro('foreignUlidAscii', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 26)->charset('ascii')->collation('ascii_bin');
        });

        Blueprint::macro('asciiString', function (string $column, int $length = 255): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->string($column, $length)->charset('ascii')->collation('ascii_bin');
        });

        Blueprint::macro('asciiChar', function (string $column, int $length): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, $length)->charset('ascii')->collation('ascii_bin');
        });

        ColumnDefinition::macro('asciiBin', function (): ColumnDefinition {
            /** @var ColumnDefinition $this */
            return $this->charset('ascii')->collation('ascii_bin');
        });
    }
}
