<?php

declare(strict_types=1);

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/**
 * Plan 6.5 / 6.6: config/tenancy.php is the single list of tenant tables, and
 * every model on such a table uses BelongsToTenant. These tests fail when a
 * migration adds a `tenant_id` column that is not registered, or a model
 * forgets the trait.
 */

/**
 * @return list<string>
 */
function tablesWithTenantIdColumn(): array
{
    $rows = DB::select(<<<'SQL'
        SELECT TABLE_NAME AS name
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND COLUMN_NAME = 'tenant_id'
         ORDER BY TABLE_NAME
        SQL);

    $tables = [];

    foreach ($rows as $row) {
        $name = ((array) $row)['name'] ?? null;

        if (is_string($name)) {
            $tables[] = $name;
        }
    }

    return $tables;
}

/**
 * @return list<class-string<Model>>
 */
function applicationModels(): array
{
    $models = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $models[] = $class;
        }
    }

    return $models;
}

it('registers every table with a tenant_id column in config/tenancy.php', function (): void {
    $configured = config('tenancy.tenant_tables');

    expect($configured)->toBeArray();
    expect(tablesWithTenantIdColumn())->toEqualCanonicalizing($configured);
});

it('only lists tables that exist', function (): void {
    foreach ([...config()->array('tenancy.tenant_tables'), ...config()->array('tenancy.team_scoped_tables')] as $table) {
        $table = is_string($table) ? $table : '';
        expect(Schema::hasTable($table))->toBeTrue("Table [{$table}] is listed in config/tenancy.php but does not exist.");
    }
});

it('uses BelongsToTenant on every model whose table has a tenant_id column', function (): void {
    $tenantTables = tablesWithTenantIdColumn();
    $checked = 0;

    foreach (applicationModels() as $class) {
        $table = (new $class)->getTable();

        if (! in_array($table, $tenantTables, true)) {
            continue;
        }

        $checked++;
        expect(in_array(BelongsToTenant::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} is on tenant table [{$table}] but does not use BelongsToTenant.");
    }

    expect($checked)->toBeGreaterThanOrEqual(count($tenantTables));
});
