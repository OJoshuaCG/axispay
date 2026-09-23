<?php

declare(strict_types=1);

namespace Tests\PHPStan\Fixtures;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fixture for TenantTableAccessRule. Excluded from the main PHPStan run; the
 * rule test analyses it and expects an error on each line marked "error".
 */
final class TenantTableAccessViolations
{
    public function run(ConnectionInterface $connection, string $dynamic, User $user): void
    {
        DB::table('users')->get(); // error: tenant table
        DB::table('audit_logs as a')->count(); // error: tenant table with alias
        $connection->table('user_invitations')->get(); // error: tenant table via connection
        DB::select('select * from users where id = ?', [1]); // error: raw SQL on tenant table
        DB::statement('UPDATE `model_has_roles` SET team_id = NULL'); // error: team-scoped table
        DB::select($dynamic); // error: non-literal raw SQL
        User::query()->withoutGlobalScope(TenantScope::class)->get(); // error: not whitelisted
        User::withoutGlobalScopes()->get(); // error: not whitelisted
        DB::table($dynamic)->get(); // error: non-literal table name
        DB::query()->from('users')->get(); // error: from() on a tenant table
        DB::query()->from('cache')->join('user_invitations', 'a', '=', 'b')->get(); // error: join() on a tenant table
        DB::query()->from($dynamic)->get(); // error: non-literal from()
        $user->newQueryWithoutScopes()->get(); // error: scope bypass
        $user->newModelQuery()->get(); // error: scope bypass
        User::query()->getQuery()->get(); // error: base query without scopes
        $query = User::query();
        $query->{$dynamic}(); // error: dynamic method call
    }

    public function allowed(): void
    {
        DB::table('cache')->count();
        DB::select('select 1');
        DB::table('usersettings')->count();
        DB::query()->from('cache')->leftJoin('jobs', fn ($join) => $join)->get();
        User::query()->where('name', 'x')->get();
    }
}
