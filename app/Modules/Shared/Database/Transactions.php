<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database;

use Illuminate\Database\DatabaseTransactionsManager;

/**
 * Whether application code is inside a database transaction. Used by the
 * guards that forbid calling a gateway or a merchant while a transaction (and
 * its row locks) is open (rules.md rule 7b), and by the code that must run
 * inside one. It asks Laravel's transaction manager instead of
 * `DB::transactionLevel()`, so the wrapping transaction of database tests
 * (RefreshDatabase) is not mistaken for application work.
 */
final class Transactions
{
    public static function open(): bool
    {
        $manager = app('db.transactions');

        return $manager instanceof DatabaseTransactionsManager && $manager->callbackApplicableTransactions()->isNotEmpty();
    }
}
