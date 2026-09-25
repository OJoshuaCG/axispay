<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Database\DatabaseManager;

/**
 * Signs an account out everywhere by deleting its rows from the session
 * table. Tenant users and platform admins both have ULID keys, so a
 * `user_id` never matches the other kind of account. `sessions` is not a
 * tenant table (config/tenancy.php).
 *
 * Only the `database` session driver stores the owner of a session; with any
 * other driver (or a custom SESSION_TABLE) nothing is deleted and null is
 * returned, so callers can say so.
 */
final readonly class SessionRevoker
{
    public function __construct(private DatabaseManager $db) {}

    public function revokeAll(User|PlatformAdmin $account): ?int
    {
        // A literal table name keeps the tenant-table PHPStan rule able to check it.
        if (config('session.driver') !== 'database' || config('session.table', 'sessions') !== 'sessions') {
            return null;
        }

        $connection = config('session.connection');

        return $this->db->connection(is_string($connection) && $connection !== '' ? $connection : null)
            ->table('sessions')
            ->where('user_id', $account->id)
            ->delete();
    }
}
