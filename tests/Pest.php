<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Shared\Logging\RedactSensitiveLogData;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use Database\Seeders\PermissionCatalogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Livewire\Features\SupportTesting\Testable;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Test case binding
|--------------------------------------------------------------------------
|
| Every test boots the Laravel application (config is needed even by money
| and ID tests). Feature tests hit the real MariaDB configured in phpunit.xml.
|
| Phase 1 feature areas run inside a transaction (RefreshDatabase) with the
| permission catalog seeded. Tests that need DDL (probe tables) live in
| Feature/Database and Feature/Schema and manage their own tables, because
| DDL commits implicitly in MariaDB.
|
*/

pest()->extend(TestCase::class)->in('Unit', 'Feature', 'Contract');

pest()->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        seed(PermissionCatalogSeeder::class);
    })
    ->in('Feature/Tenancy', 'Feature/Identity', 'Feature/Access', 'Feature/Audit', 'Feature/PlatformAdmin', 'Feature/Panels', 'Feature/Isolation', 'Feature/Console', 'Feature/Gateways', 'Feature/ApiKeys', 'Feature/PaymentLinks', 'Feature/Checkout', 'Feature/Payments', 'Feature/Legal', 'Feature/Branding', 'Feature/Webhooks', 'Feature/Fx');

/*
| Gateway tests (Phase 2): the platform test key is a dummy and Stripe's HTTP
| layer is faked for every test, so no test can reach the real Stripe API.
| The `stripe` group (tests/Contract) is the only exception, and runs only
| when STRIPE_TEST_SECRET is exported in the shell.
*/
pest()->beforeEach(function (): void {
    config(['services.stripe.test.secret' => 'sk_test_platformdummy']);
    FakeStripeHttpClient::install();
})->afterEach(function (): void {
    FakeStripeHttpClient::uninstall();
})->in('Feature/Gateways');

function stripeHttp(): FakeStripeHttpClient
{
    return FakeStripeHttpClient::current();
}

/**
 * The exception `$call` throws, which must be a `$class`.
 *
 * @template T of Throwable
 *
 * @param  class-string<T>  $class
 * @return T
 */
function thrownBy(string $class, Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }

        throw $e;
    }

    throw new LogicException("Expected [{$class}] to be thrown.");
}

/**
 * A JSON object decoded as an array (empty when it is not an object).
 *
 * @return array<mixed>
 */
function jsonArray(string $json): array
{
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Submits a Filament action form the way a browser does: the deferred
 * `wire:model` values travel in the SAME request as `callMountedAction`
 * (Filament's callAction() sends them in a separate `set` request first).
 * Needed wherever a page erases secrets from its state after each request.
 *
 * @template TComponent of \Livewire\Component
 *
 * @param  Testable<TComponent>  $component
 * @param  array<mixed>  $data  field => value
 * @return Testable<TComponent>
 */
function submitAction(Testable $component, string $name, array $data, bool $mount = true): Testable
{
    if ($mount) {
        $component->call('mountAction', $name);
    }

    $updates = [];

    foreach ($data as $field => $value) {
        $updates["mountedActions.0.data.{$field}"] = $value;
    }

    return $component->update(
        calls: [['method' => 'callMountedAction', 'params' => [[]], 'path' => '']],
        updates: $updates,
    );
}

/**
 * Absolute URL on the public checkout host (plan 11.1).
 */
function payUrl(string $path = '/'): string
{
    return 'http://'.config()->string('axispay.surfaces.pay').'/'.ltrim($path, '/');
}

/** An artisan command with output mocking (assertions available). */
function artisanCommand(string $command): PendingCommand
{
    $pending = artisan($command);

    return $pending instanceof PendingCommand ? $pending : throw new LogicException('Console output mocking must be enabled.');
}

function tenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/**
 * Routes the default log channel to an in-memory handler with the same
 * redaction tap as the real channels.
 */
function captureDefaultLog(): TestHandler
{
    config([
        'logging.channels.test_capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'tap' => [RedactSensitiveLogData::class]],
        'logging.default' => 'test_capture',
    ]);

    $logger = Log::channel('test_capture');
    $monolog = $logger instanceof Logger ? $logger->getLogger() : null;
    $handler = $monolog instanceof Monolog ? ($monolog->getHandlers()[0] ?? null) : null;

    return $handler instanceof TestHandler ? $handler : throw new LogicException('The capture channel must use a TestHandler.');
}

/**
 * Absolute URL on the public API host (ADR-027).
 */
function apiUrl(string $path): string
{
    $host = config('axispay.surfaces.api');

    return 'http://'.(is_string($host) ? $host : 'api.localhost').'/'.ltrim($path, '/');
}

/**
 * Absolute URL on the tenant panel host.
 */
function appUrl(string $path = '/'): string
{
    return 'http://'.config()->string('axispay.surfaces.app').'/'.ltrim($path, '/');
}

/**
 * Absolute URL on the platform panel host.
 */
function adminUrl(string $path = '/'): string
{
    return 'http://'.config()->string('axispay.surfaces.admin').'/'.ltrim($path, '/');
}

/**
 * A tenant user with the given system roles, created in the tenant's context.
 *
 * @param  list<SystemRole>  $roles
 */
function tenantUser(?Tenant $tenant = null, array $roles = [SystemRole::Owner], bool $twoFactor = true): User
{
    $tenant ??= Tenant::factory()->create();
    $factory = User::factory()->forTenant($tenant);
    $factory = $twoFactor ? $factory->withTwoFactor() : $factory;

    $user = app(TenantContext::class)->runAsTenant($tenant->id, false, function () use ($factory, $roles): User {
        $user = $factory->create();

        foreach ($roles as $role) {
            $user->assignRole($role->value);
        }

        return $user;
    });

    return $user->refresh();
}

/**
 * Signs a tenant user in for panel/Livewire tests: web guard, tenant context
 * (normally set by ResolveTenantContext) and the current Filament panel.
 */
function actingAsTenantUser(User $user, bool $livemode = false): User
{
    actingAs($user, 'web');
    app(TenantContext::class)->set($user->tenant_id, $livemode);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return $user;
}

function platformAdmin(bool $superadmin = true, bool $twoFactor = true): PlatformAdmin
{
    $factory = PlatformAdmin::factory();
    $factory = $superadmin ? $factory : $factory->supportReadonly();

    return ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
}

function actingAsPlatformAdmin(PlatformAdmin $admin): PlatformAdmin
{
    actingAs($admin, 'platform');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $admin;
}

function activeTenant(TenantStatus $status = TenantStatus::Active): Tenant
{
    return Tenant::factory()->status($status)->create();
}
