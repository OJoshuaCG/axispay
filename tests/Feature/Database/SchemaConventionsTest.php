<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ProbeResource;

/**
 * Column conventions of plan sections 6.7 and 7 (ULIDs and tokens in
 * ascii_bin, DATETIME(6)) and the ULID primary key trait (ADR-020).
 *
 * No RefreshDatabase here: DDL commits implicitly in MariaDB, so the probe
 * table is created and dropped explicitly around each test.
 */
beforeEach(function (): void {
    Schema::dropIfExists(ProbeResource::TABLE);
    Schema::create(ProbeResource::TABLE, function (Blueprint $table): void {
        $table->ulidAscii('id')->primary();
        $table->foreignUlidAscii('tenant_id')->nullable();
        $table->asciiString('idempotency_key', 255)->nullable();
        $table->asciiChar('key_hash', 64)->nullable()->unique();
        $table->string('provider_payment_id', 255)->asciiBin()->nullable();
        $table->string('label', 120)->default('');
        $table->dateTime('paid_at', 6)->nullable();
        $table->datetimes(6);
    });
});

afterEach(function (): void {
    Schema::dropIfExists(ProbeResource::TABLE);
});

/**
 * @return array<string, array<array-key, mixed>>
 */
function probeColumns(): array
{
    $rows = DB::select(<<<'SQL'
        SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, CHARACTER_SET_NAME AS charset, COLLATION_NAME AS collation
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
        SQL, [ProbeResource::TABLE]);

    $columns = [];

    foreach ($rows as $row) {
        $row = (array) $row;

        if (is_string($row['name'])) {
            $columns[$row['name']] = $row;
        }
    }

    return $columns;
}

it('stores identifiers, tokens and hashes as ascii_bin', function (string $column, string $type): void {
    $definition = probeColumns()[$column];

    expect($definition['type'])->toBe($type)
        ->and($definition['charset'])->toBe('ascii')
        ->and($definition['collation'])->toBe('ascii_bin');
})->with([
    ['id', 'char(26)'],
    ['tenant_id', 'char(26)'],
    ['idempotency_key', 'varchar(255)'],
    ['key_hash', 'char(64)'],
    ['provider_payment_id', 'varchar(255)'],
]);

it('keeps text columns on the default collation', function (): void {
    expect(probeColumns()['label']['collation'])->toBe('utf8mb4_uca1400_ai_ci');
});

it('uses DATETIME(6) for business and audit datetimes', function (string $column): void {
    expect(probeColumns()[$column]['type'])->toBe('datetime(6)');
})->with(['paid_at', 'created_at', 'updated_at']);

it('treats ascii_bin values as case-sensitive', function (): void {
    DB::table(ProbeResource::TABLE)->insert([
        ['id' => '01J8Z3Q6T4Y0V8KX2M1N5P7R9A', 'key_hash' => str_repeat('a', 64)],
        ['id' => '01J8Z3Q6T4Y0V8KX2M1N5P7R9B', 'key_hash' => str_repeat('A', 64)],
    ]);

    expect(DB::table(ProbeResource::TABLE)->where('key_hash', str_repeat('A', 64))->count())->toBe(1)
        ->and(fn () => DB::table(ProbeResource::TABLE)->insert(['id' => '01J8Z3Q6T4Y0V8KX2M1N5P7R9C', 'key_hash' => str_repeat('a', 64)]))
        ->toThrow(QueryException::class);
});

it('generates canonical ULID primary keys and exposes prefixed IDs', function (): void {
    $model = ProbeResource::query()->create(['label' => 'probe']);

    expect($model->id)->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and($model->prefixedId())->toBe('plink_'.$model->id)
        ->and(ProbeResource::query()->find($model->id)?->label)->toBe('probe')
        ->and(ProbeResource::query()->find(strtolower($model->id)))->toBeNull();
});

it('stores microsecond precision in UTC', function (): void {
    $model = ProbeResource::query()->create(['label' => 'probe', 'paid_at' => '2026-09-23 18:30:00.123456']);

    $raw = DB::table(ProbeResource::TABLE)->where('id', $model->id)->value('paid_at');

    expect($raw)->toBe('2026-09-23 18:30:00.123456');
});
