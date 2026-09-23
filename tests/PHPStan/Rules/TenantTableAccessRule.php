<?php

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use RuntimeException;

/**
 * Enforces the tenancy prohibitions of plan 6.5 / rules.md rule 2:
 *
 *  1. `DB::table('<tenant table>')`, `->table()` on a connection, and
 *     `->from()` / `->join()` (and variants) on a query builder naming a
 *     tenant table, or with a table name the rule cannot read (non-literal);
 *  2. raw SQL (`DB::select/insert/update/delete/statement/...`) that names a
 *     tenant table, or whose SQL is not a literal the rule can inspect;
 *  3. scope bypasses outside the whitelisted classes: `withoutGlobalScope(s)`,
 *     `withoutGlobalScopesExcept`, `newQueryWithoutScopes`,
 *     `newQueryWithoutScope`, `newModelQuery`, and `getQuery()` on an
 *     Eloquent builder (the base query never gets the scopes);
 *  4. dynamic method calls (`$query->{$method}()`) on query builders and
 *     models, which the rule cannot verify.
 *
 * Tenant tables and the whitelist come from config/tenancy.php, the same file
 * the isolation tests read. Migrations are exempt (DDL and triggers). The
 * project's own tests/ directory may bypass the scope (it asserts cross-tenant
 * state) but not use raw SQL on tenant tables; the rule fixtures are never
 * exempt.
 *
 * @implements Rule<CallLike>
 */
final class TenantTableAccessRule implements Rule
{
    private const array RAW_METHODS = [
        'select', 'selectone', 'selectresultsets', 'scalar', 'cursor',
        'insert', 'update', 'delete', 'statement', 'affectingstatement', 'unprepared',
    ];

    private const array TABLE_METHODS = ['from', 'join', 'leftjoin', 'rightjoin', 'crossjoin', 'joinwhere', 'leftjoinwhere', 'rightjoinwhere', 'straightjoin'];

    private const array SCOPE_BYPASS_METHODS = [
        'withoutglobalscope', 'withoutglobalscopes', 'withoutglobalscopesexcept',
        'newquerywithoutscopes', 'newquerywithoutscope', 'newmodelquery',
    ];

    /** @var list<string> */
    private array $tables;

    /** @var list<string> */
    private array $whitelist;

    private string $testsRoot;

    private string $migrationsRoot;

    public function __construct(string $configPath)
    {
        $config = is_file($configPath) ? require $configPath : null;

        if (! is_array($config)) {
            throw new RuntimeException("Cannot read the tenancy config at [{$configPath}].");
        }

        $this->tables = array_values(array_unique(array_filter(
            [...self::strings($config['tenant_tables'] ?? []), ...self::strings($config['team_scoped_tables'] ?? [])],
        )));
        $this->whitelist = self::strings($config['scope_bypass_whitelist'] ?? []);

        $root = self::normalizePath(dirname((string) realpath($configPath), 2));
        $this->testsRoot = $root.'/tests/';
        $this->migrationsRoot = $root.'/database/migrations/';
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $file = self::normalizePath($scope->getFile());

        if (str_starts_with($file, $this->migrationsRoot)) {
            return [];
        }

        if (! $node instanceof MethodCall && ! $node instanceof NullsafeMethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        if (! $node->name instanceof Identifier) {
            return $this->checkDynamicCall($node, $scope);
        }

        $method = strtolower($node->name->toString());

        if (in_array($method, self::SCOPE_BYPASS_METHODS, true) || ($method === 'getquery' && $this->isEloquentBuilderCall($node, $scope))) {
            return $this->isTestCode($file) ? [] : $this->checkScopeBypass($scope, $node->name->toString());
        }

        if (in_array($method, self::TABLE_METHODS, true) && $this->isQueryBuilderCall($node, $scope)) {
            return $this->checkTable($node, $scope, $node->name->toString());
        }

        if (! $this->isDatabaseCall($node, $scope)) {
            return [];
        }

        if ($method === 'table') {
            return $this->checkTable($node, $scope, 'table');
        }

        if (in_array($method, self::RAW_METHODS, true)) {
            return $this->checkRawSql($node, $scope);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkScopeBypass(Scope $scope, string $method): array
    {
        $class = $scope->getClassReflection()?->getName();

        if ($class !== null && $this->isWhitelisted($class)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Calling %s() outside the tenancy scope-bypass whitelist is forbidden (plan 6.5). Use TenantContext::runAsPlatform() or add a justified entry to config/tenancy.php.',
                $method,
            ))->identifier('paylink.scopeBypass')->build(),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkDynamicCall(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): array
    {
        $type = $node instanceof StaticCall
            ? ($node->class instanceof Name ? new ObjectType($scope->resolveName($node->class)) : null)
            : $scope->getType($node->var);

        if ($type === null || ! ($this->isQueryType($type) || (new ObjectType(Model::class))->isSuperTypeOf($type)->yes())) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Dynamic method calls on query builders or models cannot be verified by the tenancy rule (plan 6.5); call the method by name.')
                ->identifier('paylink.dynamicQueryCall')
                ->build(),
        ];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkTable(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope, string $method): array
    {
        $args = $node->getArgs();

        if ($args === []) {
            return [];
        }

        $argType = $scope->getType($args[0]->value);

        // from()/join() also take closures, sub-queries and expressions; only
        // plain strings name a table.
        if ($argType->isString()->no()) {
            return [];
        }

        $tables = [];

        foreach ($argType->getConstantStrings() as $constant) {
            $tables[] = $constant->getValue();
        }

        if ($tables === []) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'Cannot verify the table name passed to %s(): use a string literal so the tenant-table check can run (plan 6.5).',
                    $method,
                ))->identifier('paylink.tenantTableAccess')->build(),
            ];
        }

        foreach ($tables as $name) {
            $name = strtolower(trim(preg_split('/\s+as\s+/i', $name)[0] ?? $name));

            if (in_array($name, $this->tables, true)) {
                return [
                    RuleErrorBuilder::message($method === 'table'
                        ? sprintf('DB::table(\'%s\') bypasses the fail-closed tenant scope; use the Eloquent model (plan 6.5).', $name)
                        : sprintf('%s(\'%s\') reads a tenant table without its tenant scope; use the Eloquent model or a relation (plan 6.5).', $method, $name))
                        ->identifier('paylink.tenantTableAccess')
                        ->build(),
                ];
            }
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkRawSql(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): array
    {
        $sql = $this->firstArgumentStrings($node, $scope);

        if ($sql === null) {
            return [
                RuleErrorBuilder::message('Raw SQL must be a string literal so the tenant-table check can inspect it (plan 6.5).')
                    ->identifier('paylink.rawTenantSql')
                    ->build(),
            ];
        }

        foreach ($sql as $statement) {
            foreach ($this->tables as $table) {
                if (preg_match('/(?<![A-Za-z0-9_])`?'.preg_quote($table, '/').'`?(?![A-Za-z0-9_])/i', $statement) === 1) {
                    return [
                        RuleErrorBuilder::message(sprintf(
                            'Raw SQL on tenant table [%s] bypasses the fail-closed tenant scope (plan 6.5).',
                            $table,
                        ))->identifier('paylink.rawTenantSql')->build(),
                    ];
                }
            }
        }

        return [];
    }

    private function isDatabaseCall(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): bool
    {
        if ($node instanceof StaticCall) {
            return $node->class instanceof Name
                && ltrim($scope->resolveName($node->class), '\\') === DB::class;
        }

        $type = $scope->getType($node->var);

        return (new ObjectType(ConnectionInterface::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(DatabaseManager::class))->isSuperTypeOf($type)->yes();
    }

    private function isQueryBuilderCall(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): bool
    {
        return ! $node instanceof StaticCall && $this->isQueryType($scope->getType($node->var));
    }

    private function isEloquentBuilderCall(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): bool
    {
        return ! $node instanceof StaticCall
            && (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($scope->getType($node->var))->yes();
    }

    private function isQueryType(Type $type): bool
    {
        return (new ObjectType(QueryBuilder::class))->isSuperTypeOf($type)->yes()
            || (new ObjectType(EloquentBuilder::class))->isSuperTypeOf($type)->yes();
    }

    /**
     * Constant string values of the first argument, or null when it is not a
     * constant string (or absent).
     *
     * @return list<string>|null
     */
    private function firstArgumentStrings(MethodCall|NullsafeMethodCall|StaticCall $node, Scope $scope): ?array
    {
        $args = $node->getArgs();

        if ($args === []) {
            return null;
        }

        $values = [];

        foreach ($scope->getType($args[0]->value)->getConstantStrings() as $constant) {
            $values[] = $constant->getValue();
        }

        return $values === [] ? null : $values;
    }

    /**
     * Only the project's own tests/ directory, never the rule fixtures.
     */
    private function isTestCode(string $file): bool
    {
        return str_starts_with($file, $this->testsRoot)
            && ! str_starts_with($file, $this->testsRoot.'PHPStan/Fixtures/');
    }

    private function isWhitelisted(string $class): bool
    {
        foreach ($this->whitelist as $entry) {
            $entry = ltrim($entry, '\\');

            if (str_ends_with($entry, '\\') ? str_starts_with($class, $entry) : $class === $entry) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
