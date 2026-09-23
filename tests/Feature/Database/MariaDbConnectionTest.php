<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 acceptance (plan section 27): the connection runs on MariaDB 11.8
 * with utf8mb4 / utf8mb4_uca1400_ai_ci, strict sql_mode and UTC.
 */
uses(RefreshDatabase::class);

/**
 * @return array<array-key, mixed>
 */
function sessionVariables(): array
{
    $row = DB::selectOne(<<<'SQL'
        SELECT VERSION()                    AS version,
               @@character_set_connection   AS charset_connection,
               @@character_set_client       AS charset_client,
               @@character_set_results      AS charset_results,
               @@collation_connection       AS collation_connection,
               @@character_set_database     AS charset_database,
               @@collation_database         AS collation_database,
               @@session.sql_mode           AS sql_mode,
               @@session.time_zone          AS time_zone,
               @@default_storage_engine     AS storage_engine
        SQL);

    return (array) $row;
}

it('connects to MariaDB 11.8 through the mariadb driver', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mariadb')
        ->and(sessionVariables()['version'])->toStartWith('11.8.');
});

it('uses utf8mb4 with the utf8mb4_uca1400_ai_ci collation', function (): void {
    $vars = sessionVariables();

    expect($vars['charset_connection'])->toBe('utf8mb4')
        ->and($vars['charset_client'])->toBe('utf8mb4')
        ->and($vars['charset_results'])->toBe('utf8mb4')
        ->and($vars['collation_connection'])->toBe('utf8mb4_uca1400_ai_ci')
        ->and($vars['charset_database'])->toBe('utf8mb4')
        ->and($vars['collation_database'])->toBe('utf8mb4_uca1400_ai_ci');
});

it('runs every session with the strict sql_mode flags', function (string $flag): void {
    $sqlMode = sessionVariables()['sql_mode'];
    $modes = is_string($sqlMode) ? explode(',', $sqlMode) : [];

    expect($modes)->toContain($flag);
})->with([
    'STRICT_TRANS_TABLES',
    'ERROR_FOR_DIVISION_BY_ZERO',
    'NO_ENGINE_SUBSTITUTION',
    'NO_ZERO_DATE',
    'NO_ZERO_IN_DATE',
]);

it('runs sessions in UTC with InnoDB as the default engine', function (): void {
    $vars = sessionVariables();

    expect($vars['time_zone'])->toBe('+00:00')
        ->and($vars['storage_engine'])->toBe('InnoDB');
});

it('creates every migrated table as InnoDB with the default collation', function (): void {
    $tables = DB::select(<<<'SQL'
        SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_COLLATION AS collation
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
        SQL);

    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $table = (array) $table;
        $name = is_string($table['name']) ? $table['name'] : '?';

        expect($table['engine'])->toBe('InnoDB', "{$name} is not InnoDB")
            ->and($table['collation'])->toBe('utf8mb4_uca1400_ai_ci', "{$name} has another collation");
    }
});

it('fails loudly instead of silently truncating data', function (): void {
    // DDL outside of a transaction: MariaDB commits implicitly on CREATE/DROP.
    DB::statement('CREATE TEMPORARY TABLE strict_mode_probe (code VARCHAR(3) NOT NULL, happened_on DATE NULL)');

    expect(fn () => DB::insert("INSERT INTO strict_mode_probe (code) VALUES ('TOOLONG')"))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::insert("INSERT INTO strict_mode_probe (code, happened_on) VALUES ('ok', '0000-00-00')"))
        ->toThrow(QueryException::class);

    DB::statement('DROP TEMPORARY TABLE strict_mode_probe');
});

it('reports the schema of the default migrations as present', function (): void {
    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('jobs'))->toBeTrue();
});
