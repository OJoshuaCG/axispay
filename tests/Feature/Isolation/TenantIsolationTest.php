<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Access\Filament\Resources\Roles\Pages\ListRoles;
use App\Modules\Access\Models\Role;
use App\Modules\ApiKeys\Filament\Resources\ApiKeys\Pages\ListApiKeys;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Identity\Filament\Resources\Users\Pages\ListUsers;
use App\Modules\Identity\Models\User;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Filament\Resources\Payments\Pages\ListPayments;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Actions\ResendWebhookDelivery;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Filament\Pages\PrePaymentValidationSettings;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;
use Tests\Support\BrandingTestHelpers as Images;
use Tests\Support\CheckoutTestHelpers;
use Tests\Support\FakeHostResolver;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\ValidationTestHelpers;
use Tests\Support\WebhookTestHelpers;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/**
 * Tenant isolation (plan 6.6). For every tenant-panel resource, a user of
 * tenant A never sees tenant B's rows in lists and gets a 404 (never 403) on
 * B's records. The route coverage test below fails when a new tenant route is
 * registered without being added to this dataset (or to the reviewed list of
 * non-resource routes).
 */

/**
 * Resource URL slug => [list page, record factory for a given tenant, has a
 * record (view) page].
 *
 * @return array<string, array{0: class-string, 1: Closure(Tenant): Model, 2?: bool}>
 */
function isolatedAppResources(): array
{
    return [
        'payment-links' => [ListPaymentLinks::class, static fn (Tenant $tenant): Model => ApiTestHelpers::link($tenant)],
        // List only: keys have no record page.
        'settings/api-keys' => [ListApiKeys::class, static fn (Tenant $tenant): Model => ApiTestHelpers::key($tenant)[0], false],
        'users' => [ListUsers::class, static fn (Tenant $tenant): Model => tenantUser($tenant, [SystemRole::Viewer])],
        'roles' => [ListRoles::class, static fn (Tenant $tenant): Model => app(TenantContext::class)->runAsTenant(
            $tenant->id,
            false,
            static fn (): Model => Role::query()->create(['name' => 'custom-'.$tenant->id, 'guard_name' => TenantPermission::GUARD, 'team_id' => $tenant->id]),
        )],
        'audit-logs' => [ListAuditLogs::class, static fn (Tenant $tenant): Model => app(AuditLogger::class)->record(AuditAction::LivemodeSwitched, tenantId: $tenant->id)],
        'settings/webhooks' => [ListWebhookEndpoints::class, static fn (Tenant $tenant): Model => WebhookTestHelpers::endpoint($tenant)],
        'payments' => [ListPayments::class, static function (Tenant $tenant): Model {
            GatewayTestHelpers::connection($tenant);
            $link = ApiTestHelpers::link($tenant);

            return CheckoutTestHelpers::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::factory()->createOne([
                'payment_link_id' => $link->id,
                'gateway_connection_id' => CheckoutTestHelpers::connectionOf($link)->id,
            ]));
        }],
    ];
}

/**
 * App-host routes that are not Filament resources, each reviewed for
 * isolation: none takes a tenant record from the URL.
 *
 * @var array<string, string>
 */
const REVIEWED_APP_ROUTES = [
    'invitations.show' => 'Token lookup only; the tenant comes from the invitation.',
    'invitations.accept' => 'Token lookup only; the tenant comes from the invitation.',
    'impersonation.consume' => 'Token lookup only; covered by ImpersonationTest.',
    'impersonation.stop' => 'Acts on the session only.',
    'app.livemode.update' => 'Acts on the session only; tenant from the user.',
    'app.session.ping' => 'Keep-alive (ADR-0040): no parameters, reads nothing, returns an empty 204.',
    'gateways.stripe.onboarding.return' => 'Connection resolved through the tenant scope; cross-tenant 404 covered by Gateways/GatewayIsolationTest.',
    'gateways.stripe.onboarding.refresh' => 'Connection resolved through the tenant scope; cross-tenant 404 covered by Gateways/GatewayIsolationTest.',
    'app.branding.platform-logo' => 'Platform asset (ADR-0053): no tenant data and no tenant parameter, read from the platform table by variant and version only; cookie-less and sessionless, the same bytes for any signed-in tenant or guest, and a merchant\'s logo version answers 404 (tested below).',
    'app.branding.favicon' => 'Platform asset (ADR-0053): no tenant data and no tenant parameter, read from the platform table by size and version only; cookie-less and sessionless, the same bytes for any signed-in tenant or guest (tested below).',
];

/**
 * API-host routes that are not tenant API resources: platform-level
 * endpoints authenticated by a signature, not by a tenant API key.
 *
 * @var array<string, string>
 */
const REVIEWED_API_ROUTES = [
    'webhooks.stripe.connect' => 'Stripe Connect webhooks (plan 14.1): tenant derived from event.account; covered by Gateways/IncomingWebhooksTest.',
    'webhooks.stripe.direct' => 'Stripe direct webhooks (plan 14.1): per-connection secret, unknown/disconnected -> 404; covered by Gateways/IncomingWebhooksTest.',
];

/**
 * Asserts that $viewer (tenant A) cannot list or open $foreign (tenant B),
 * while they can list and open $own.
 *
 * @param  class-string  $listPage
 */
function assertTenantIsolation(User $viewer, string $slug, string $listPage, Model $own, Model $foreign, bool $hasRecordPage = true): void
{
    actingAsTenantUser($viewer);

    Livewire::test($listPage)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);

    if (! $hasRecordPage) {
        get(appUrl("/{$slug}/".routeKeyOf($foreign)))->assertNotFound();

        return;
    }

    get(appUrl("/{$slug}/".routeKeyOf($own)))->assertOk();
    get(appUrl("/{$slug}/".routeKeyOf($foreign)))->assertNotFound();
}

function routeKeyOf(Model $model): string
{
    $key = $model->getRouteKey();

    return is_string($key) ? $key : throw new LogicException('Expected a string route key.');
}

dataset('isolated_app_resources', fn (): array => array_map(
    static fn (array $resource, string $slug): array => [$slug, ...$resource],
    isolatedAppResources(),
    array_keys(isolatedAppResources()),
));

it('isolates tenant panel resources between tenants', function (string $slug, string $listPage, Closure $factory, bool $hasRecordPage = true): void {
    /** @var class-string $listPage */
    [$a, $b] = [activeTenant(), activeTenant()];
    $viewer = tenantUser($a, [SystemRole::Owner]);
    $own = $factory($a);
    $foreign = $factory($b);
    assert($own instanceof Model && $foreign instanceof Model);

    assertTenantIsolation($viewer, $slug, $listPage, $own, $foreign, $hasRecordPage);
})->with('isolated_app_resources');

it('serves the platform logo and favicon identically to every tenant and to guests, never a merchant\'s logo (ADR-0053)', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    $merchantLogo = Images::storeTenantLogo($a);
    $logoBytes = Images::png(30, 10);
    $platformLogo = Images::storeLogo(LogoVariant::Light, $logoBytes);
    $favicon = Images::storeFavicon()[32];
    $assets = [Images::url($platformLogo) => $logoBytes, Images::faviconUrl($favicon) => $favicon->content];

    foreach ([null, tenantUser($a, [SystemRole::Owner]), tenantUser($b, [SystemRole::Owner])] as $viewer) {
        if ($viewer instanceof User) {
            actingAsTenantUser($viewer);
        }

        // A merchant's logo version is unknown to the platform route: 404.
        get(appUrl('/branding/platform-logo/light/'.$merchantLogo->version.'.png'))->assertNotFound();

        foreach ($assets as $path => $bytes) {
            $response = get(appUrl($path))->assertOk();

            expect($response->getContent())->toBe($bytes)
                ->and($response->headers->getCookies())->toBe([]);
        }
    }
});

it('never shows or resends another tenant\'s webhook deliveries (plan 6.6)', function (): void {
    FakeHostResolver::install();
    [$a, $b] = [activeTenant(), activeTenant()];
    $viewer = tenantUser($a, [SystemRole::Owner]);
    $foreignEndpoint = WebhookTestHelpers::endpoint($b);
    $foreign = WebhookTestHelpers::in($b, false, static function () use ($foreignEndpoint): WebhookDelivery {
        $event = new WebhookEvent;
        $event->forceFill(['livemode' => false, 'type' => 'payment.succeeded', 'payload' => '{}'])->save();

        $delivery = new WebhookDelivery;
        $delivery->forceFill([
            'livemode' => false,
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $foreignEndpoint->id,
            'trigger' => WebhookDeliveryTrigger::Automatic,
            'attempt_number' => 1,
            'status' => WebhookDeliveryStatus::Abandoned,
            'scheduled_at' => now(),
        ])->save();

        return $delivery;
    });
    actingAsTenantUser($viewer);

    Livewire::test(ViewWebhookEndpoint::class, ['record' => $foreignEndpoint->getRouteKey()])->assertNotFound();

    // The action reached directly: the foreign delivery's endpoint is invisible in A's tenant.
    expect(fn () => app(ResendWebhookDelivery::class)->handle($viewer, $foreign))->toThrow(ModelNotFoundException::class)
        ->and(WebhookTestHelpers::deliveries($b))->toHaveCount(1);
});

it('never lists another tenant\'s pre-payment validation calls (plan 6.6)', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    ValidationTestHelpers::endpoint($a);
    $foreign = ValidationTestHelpers::in($b, false, static function (): ValidationCall {
        $call = new ValidationCall;
        $call->forceFill(['livemode' => false, 'is_test' => true, 'request_payload' => []])->save();

        return $call;
    });
    $own = ValidationTestHelpers::in($a, false, static function (): ValidationCall {
        $call = new ValidationCall;
        $call->forceFill(['livemode' => false, 'is_test' => true, 'request_payload' => []])->save();

        return $call;
    });
    actingAsTenantUser(tenantUser($a, [SystemRole::Owner]));

    Livewire::test(PrePaymentValidationSettings::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('covers every tenant-panel route with an isolation test', function (): void {
    $appHost = config()->string('axispay.surfaces.app');
    $covered = array_keys(isolatedAppResources());
    $uncovered = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if ($route->getDomain() !== $appHost || str_starts_with($name, 'filament.app.auth.') || $name === 'filament.app.pages.dashboard') {
            continue;
        }

        if (preg_match('/^filament\.app\.resources\.(.+)\.[^.]+$/', $name, $match) === 1) {
            if (! in_array(str_replace('.', '/', $match[1]), $covered, true)) {
                $uncovered[] = $name;
            }

            continue;
        }

        if (! array_key_exists($name, REVIEWED_APP_ROUTES) && ! str_starts_with($name, 'filament.')) {
            $uncovered[] = $name !== '' ? $name : $route->uri();
        }
    }

    expect($uncovered)->toBe([], 'Add an isolation test (dataset above) for: '.implode(', ', $uncovered));
});

/**
 * Public API routes (Phase 3): route name => a request made with tenant A's
 * key against tenant B's link. Each must answer 404 (never 403) or, for
 * lists, leave B's link out (plan 6.6).
 *
 * @return array<string, Closure(string, PaymentLink): TestResponse<Response>>
 */
function isolatedApiRoutes(): array
{
    // Tenant B's event history gets one event, whose ID the show case asks for.
    $foreignEventId = static function (PaymentLink $foreign): string {
        $tenant = Tenant::query()->findOrFail($foreign->tenant_id);
        WebhookTestHelpers::record($tenant, DomainEventType::PaymentLinkOpened);

        return PrefixedId::encode(ResourceType::Event, WebhookTestHelpers::events($tenant)[0]->id);
    };

    $headers = static fn (string $key): array => ApiTestHelpers::headers($key, 'isolation-'.bin2hex(random_bytes(4)));

    return [
        'api.v1.payment_links.show' => static fn (string $key, PaymentLink $foreign): TestResponse => withHeaders($headers($key))->getJson(apiUrl('v1/payment_links/'.$foreign->prefixedId())),
        'api.v1.payment_links.cancel' => static fn (string $key, PaymentLink $foreign): TestResponse => withHeaders($headers($key))->postJson(apiUrl('v1/payment_links/'.$foreign->prefixedId().'/cancel')),
        'api.v1.payment_links.index' => static fn (string $key, PaymentLink $foreign): TestResponse => withHeaders($headers($key))->getJson(apiUrl('v1/payment_links?client_reference_id='.$foreign->client_reference_id)),
        'api.v1.events.show' => static fn (string $key, PaymentLink $foreign): TestResponse => withHeaders($headers($key))->getJson(apiUrl('v1/events/'.$foreignEventId($foreign))),
        'api.v1.events.index' => static function (string $key, PaymentLink $foreign) use ($foreignEventId, $headers): TestResponse {
            $foreignEventId($foreign);

            return withHeaders($headers($key))->getJson(apiUrl('v1/events'));
        },
        // Creating never takes another tenant's ID; the new link belongs to the key's tenant.
        'api.v1.payment_links.store' => static fn (string $key, PaymentLink $foreign): TestResponse => withHeaders($headers($key))->postJson(apiUrl('v1/payment_links'), ApiTestHelpers::body(['client_reference_id' => $foreign->client_reference_id])),
    ];
}

dataset('isolated_api_routes', fn (): array => array_map(
    static fn (Closure $request, string $name): array => [$name, $request],
    isolatedApiRoutes(),
    array_keys(isolatedApiRoutes()),
));

it('isolates every API route between tenants', function (string $name, Closure $request): void {
    [$a, $b] = [ApiTestHelpers::readyTenant(), ApiTestHelpers::readyTenant()];
    [, $keyA] = ApiTestHelpers::key($a);
    $foreign = ApiTestHelpers::link($b, state: static fn ($f) => $f->state(['client_reference_id' => 'FOREIGN-1']));

    $response = $request($keyA, $foreign);
    assert($response instanceof TestResponse);

    match ($name) {
        'api.v1.payment_links.index', 'api.v1.events.index' => $response->assertOk()->assertJsonCount(0, 'data'),
        'api.v1.payment_links.store' => $response->assertCreated()->assertJsonMissingPath('error'),
        default => $response->assertNotFound()->assertJsonPath('error.code', 'resource_not_found'),
    };

    // Tenant B's link is untouched and still only B's.
    $fresh = PaymentLink::query()->withoutGlobalScopes()->findOrFail($foreign->id);
    expect($fresh->tenant_id)->toBe($b->id)->and($fresh->status->value)->toBe('active');

    if ($name === 'api.v1.payment_links.store') {
        expect(PaymentLink::query()->withoutGlobalScopes()->where('client_reference_id', 'FOREIGN-1')->pluck('tenant_id')->sort()->values()->all())
            ->toEqualCanonicalizing([$a->id, $b->id]);
    }
})->with('isolated_api_routes');

it('covers every API route with an isolation test', function (): void {
    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (RouteDefinition $route): bool => $route->getDomain() === config()->string('axispay.surfaces.api'))
        ->map(static fn (RouteDefinition $route): string => (string) $route->getName())
        ->reject(static fn (string $name): bool => array_key_exists($name, REVIEWED_API_ROUTES) || array_key_exists($name, isolatedApiRoutes()))
        ->values()
        ->all();

    expect($apiRoutes)->toBe([], 'Add an API isolation case (isolatedApiRoutes) for: '.implode(', ', $apiRoutes));
});
