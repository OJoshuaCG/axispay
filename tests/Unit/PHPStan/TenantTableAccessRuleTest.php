<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\PHPStan\Rules\TenantTableAccessRule;

/**
 * The custom PHPStan rule of plan 6.5, run through PHPStan's own rule test
 * harness (a PHPUnit test case, executed by Pest).
 *
 * @extends RuleTestCase<TenantTableAccessRule>
 */
final class TenantTableAccessRuleTest extends RuleTestCase
{
    private const string BYPASS = 'outside the tenancy scope-bypass whitelist is forbidden (plan 6.5). Use TenantContext::runAsPlatform() or add a justified entry to config/tenancy.php.';

    protected function getRule(): Rule
    {
        return new TenantTableAccessRule(dirname(__DIR__, 3).'/config/tenancy.php');
    }

    public function test_it_reports_raw_access_to_tenant_tables_and_scope_bypasses(): void
    {
        $this->analyse([dirname(__DIR__, 2).'/PHPStan/Fixtures/TenantTableAccessViolations.php'], [
            ["DB::table('users') bypasses the fail-closed tenant scope; use the Eloquent model (plan 6.5).", 20],
            ["DB::table('audit_logs') bypasses the fail-closed tenant scope; use the Eloquent model (plan 6.5).", 21],
            ["DB::table('user_invitations') bypasses the fail-closed tenant scope; use the Eloquent model (plan 6.5).", 22],
            ['Raw SQL on tenant table [users] bypasses the fail-closed tenant scope (plan 6.5).', 23],
            ['Raw SQL on tenant table [model_has_roles] bypasses the fail-closed tenant scope (plan 6.5).', 24],
            ['Raw SQL must be a string literal so the tenant-table check can inspect it (plan 6.5).', 25],
            ['Calling withoutGlobalScope() '.self::BYPASS, 26],
            ['Calling withoutGlobalScopes() '.self::BYPASS, 27],
            ['Cannot verify the table name passed to table(): use a string literal so the tenant-table check can run (plan 6.5).', 28],
            ["from('users') reads a tenant table without its tenant scope; use the Eloquent model or a relation (plan 6.5).", 29],
            ["join('user_invitations') reads a tenant table without its tenant scope; use the Eloquent model or a relation (plan 6.5).", 30],
            ['Cannot verify the table name passed to from(): use a string literal so the tenant-table check can run (plan 6.5).', 31],
            ['Calling newQueryWithoutScopes() '.self::BYPASS, 32],
            ['Calling newModelQuery() '.self::BYPASS, 33],
            ['Calling getQuery() '.self::BYPASS, 34],
            ['Dynamic method calls on query builders or models cannot be verified by the tenancy rule (plan 6.5); call the method by name.', 36],
        ]);
    }

    public function test_whitelisted_classes_may_bypass_the_tenant_scope(): void
    {
        $this->analyse([dirname(__DIR__, 2).'/PHPStan/Fixtures/Whitelisted/PaymentLinkLookup.php'], []);
    }

    public function test_only_the_project_tests_directory_is_exempt(): void
    {
        // A path that merely contains "/tests/" (e.g. a vendor package) is not exempt.
        $dir = sys_get_temp_dir().'/paylink-rule-'.bin2hex(random_bytes(4)).'/vendor/package/tests';
        mkdir($dir, 0700, true);
        $file = $dir.'/BypassInVendorTests.php';
        file_put_contents($file, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Vendor\Package\Tests;

            final class BypassInVendorTests
            {
                public function run(): void
                {
                    \App\Modules\Identity\Models\User::withoutGlobalScopes()->get();
                }
            }
            PHP);

        try {
            $this->analyse([$file], [
                ['Calling withoutGlobalScopes() '.self::BYPASS, 11],
            ]);
        } finally {
            unlink($file);
        }
    }
}
