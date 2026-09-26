<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Actions\CreatePaymentLink;
use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Enums\CreatedVia;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\PaymentLinkUrl;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ApiTestHelpers;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/**
 * Payment links in the tenant panel (plan 27 Phase 3): list, detail, manual
 * create and cancel, through the same actions as the API.
 */
it('lists the links of the current mode with status, amount and filters', function (string $locale): void {
    $tenant = ApiTestHelpers::readyTenant();

    app()->setLocale($locale);
    $usd = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['description' => 'Order USD', 'client_reference_id' => 'REF-9']));
    $mxnPaid = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->paid()->state(['currency' => CurrencyCode::MXN, 'amount_minor' => 123_456, 'description' => 'Order MXN']));
    $live = ApiTestHelpers::link($tenant, livemode: true);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    Livewire::test(ListPaymentLinks::class)
        ->assertCanSeeTableRecords([$usd, $mxnPaid])
        ->assertCanNotSeeTableRecords([$live])
        ->assertSee(PaymentLinkStatus::Paid->label())
        ->assertSee(__('payment_links.page.subheading.test'))
        ->searchTable('REF-9')->assertCanSeeTableRecords([$usd])->assertCanNotSeeTableRecords([$mxnPaid])
        ->searchTable('Order MXN')->assertCanSeeTableRecords([$mxnPaid])->assertCanNotSeeTableRecords([$usd])
        ->searchTable(null)
        ->filterTable('status', 'paid')->assertCanSeeTableRecords([$mxnPaid])->assertCanNotSeeTableRecords([$usd])
        ->resetTableFilters()
        ->filterTable('currency', 'USD')->assertCanSeeTableRecords([$usd])->assertCanNotSeeTableRecords([$mxnPaid])
        ->assertActionHidden('create');
})->with(['en', 'es']);

it('shows an empty state', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(ListPaymentLinks::class)->assertSee(__('payment_links.empty.heading'));
});

it('shows the detail with the shareable URL', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['metadata' => ['order_id' => 'A-7'], 'client_reference_id' => 'A-7']));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    get(appUrl('/payment-links/'.$link->id))
        ->assertOk()
        ->assertSee(PaymentLinkUrl::for($link))
        ->assertSee($link->prefixedId())
        ->assertSee('order_id: A-7')
        ->assertSee(__('payment_links.url_help'));
});

it('is only reachable with links:read', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::IntegrationManager]));
    get(appUrl('/payment-links'))->assertOk();

    $user = tenantUser($tenant, []);
    actingAsTenantUser($user);
    get(appUrl('/payment-links'))->assertForbidden();
});

it('creates a link from the panel with the same rules as the API', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $creator = actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));

    Livewire::test(ListPaymentLinks::class)
        ->callAction('create', data: [
            'amount' => '250.50',
            'currency' => 'MXN',
            'description' => 'Invoice 55',
            'client_reference_id' => 'INV-55',
            'expiry' => 24,
            'locale' => 'en',
        ])
        ->assertHasNoActionErrors()
        ->assertRedirect();

    $link = PaymentLink::query()->sole();
    expect($link->amount_minor)->toBe(25_050)
        ->and($link->currency)->toBe(CurrencyCode::MXN)
        ->and($link->description)->toBe('Invoice 55')
        ->and($link->client_reference_id)->toBe('INV-55')
        ->and($link->locale)->toBe('en')
        ->and($link->created_via)->toBe(CreatedVia::Panel)
        ->and($link->created_by_actor_type)->toBe(ActorType::User)
        ->and($link->created_by_actor_id)->toBe($creator->id)
        ->and($link->status)->toBe(PaymentLinkStatus::Active);
});

it('shows field errors from the shared rules in the viewer language', function (string $locale, array $data, string $field, string $code): void {
    $tenant = ApiTestHelpers::readyTenant();

    app()->setLocale($locale);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));

    Livewire::test(ListPaymentLinks::class)
        ->callAction('create', data: [...['amount' => '100.00', 'currency' => 'USD', 'description' => 'x'], ...$data])
        ->assertHasActionErrors([$field]);

    expect(PaymentLink::query()->count())->toBe(0)
        ->and(__('api_errors.'.$code))->not->toBe('api_errors.'.$code);
})->with(['en', 'es'])->with([
    'bad amount' => [['amount' => '1.001'], 'amount', 'amount_invalid'],
    'below minimum' => [['amount' => '0.10'], 'amount', 'amount_below_minimum'],
    'above maximum' => [['amount' => '99999.00'], 'amount', 'amount_above_maximum'],
    'expiration too long' => [['expiry' => 'custom', 'expires_in_hours' => '5000'], 'expires_in_hours', 'expiration_out_of_range'],
]);

it('tells a suspended tenant it cannot create links and hides the button', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(ListPaymentLinks::class)->assertActionHidden('create');
});

it('shows gateway_not_ready as a notification', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(ListPaymentLinks::class)
        ->callAction('create', data: ['amount' => '100.00', 'currency' => 'USD', 'description' => 'x'])
        ->assertNotified(__('api_errors.gateway_not_ready'));

    expect(PaymentLink::query()->count())->toBe(0);
});

it('cancels a link after confirmation, with links:cancel', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $link = ApiTestHelpers::link($tenant);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));

    Livewire::test(ViewPaymentLink::class, ['record' => $link->id])
        ->assertActionVisible('cancel')
        ->callAction('cancel', data: ['reason' => 'Duplicate'])
        ->assertHasNoActionErrors()
        ->assertNotified(__('payment_links.notifications.canceled'));

    $fresh = PaymentLink::query()->findOrFail($link->id);
    expect($fresh->status)->toBe(PaymentLinkStatus::Canceled)->and($fresh->cancel_reason)->toBe('Duplicate');
});

it('hides cancel without links:cancel and on links that are not active', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $active = ApiTestHelpers::link($tenant);
    $paid = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->paid());

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    Livewire::test(ViewPaymentLink::class, ['record' => $active->id])->assertActionHidden('cancel');

    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));
    Livewire::test(ViewPaymentLink::class, ['record' => $paid->id])->assertActionHidden('cancel');
    // The list has no row actions: canceling is done from the detail.
    Livewire::test(ListPaymentLinks::class)->assertTableActionDoesNotExist('cancel');
});

it('reports a link that expired meanwhile instead of canceling it', function (): void {
    $tenant = ApiTestHelpers::readyTenant();

    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->pastExpiry());
    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));

    Livewire::test(ViewPaymentLink::class, ['record' => $link->id])
        ->callAction('cancel')
        ->assertNotified(__('api_errors.link_not_cancelable'));

    expect(PaymentLink::query()->findOrFail($link->id)->status)->toBe(PaymentLinkStatus::Expired);
});

it('keeps a suspended or closed tenant read-only in the panel: no create, no cancel (plan §21.3)', function (TenantStatus $status): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);
    $user = actingAsTenantUser(tenantUser($tenant, [SystemRole::Owner]));
    $tenant->forceFill(['status' => $status])->save();

    expect($user->can('cancel', $link))->toBeFalse()
        ->and($user->can('create', PaymentLink::class))->toBeFalse();
    Livewire::test(ViewPaymentLink::class, ['record' => $link->id])->assertActionHidden('cancel');
    Livewire::test(ListPaymentLinks::class)->assertActionHidden('create');

    // The action refuses it too, whatever the UI shows.
    $e = thrownBy(ApiException::class, fn () => app(CancelPaymentLink::class)->handleForUser($user, $link, new CancelPaymentLinkData));
    expect($e->errorCode)->toBe(ApiErrorCode::TenantSuspended)
        ->and(PaymentLink::query()->findOrFail($link->id)->status)->toBe(PaymentLinkStatus::Active);
})->with([TenantStatus::Suspended, TenantStatus::Closed]);

it('still lets the API cancel links of a suspended tenant (plan §10.2)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    [, $key] = ApiTestHelpers::key($tenant);
    $link = ApiTestHelpers::link($tenant);
    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    withHeaders(ApiTestHelpers::headers($key))->postJson(apiUrl('v1/payment_links/'.$link->prefixedId().'/cancel'))
        ->assertOk()
        ->assertJsonPath('status', 'canceled');
});

it('refuses panel creation for a suspended tenant at the action level (plan §21.3)', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $user = actingAsTenantUser(tenantUser($tenant));
    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    $e = thrownBy(ApiException::class, fn () => app(CreatePaymentLink::class)->handleForUser($user, ApiTestHelpers::parsedBody()));

    expect($e->errorCode)->toBe(ApiErrorCode::TenantSuspended)
        ->and(PaymentLink::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses panel creation without links:create at the action level', function (): void {
    $user = actingAsTenantUser(tenantUser(ApiTestHelpers::readyTenant(), [SystemRole::Viewer]));

    expect(fn () => app(CreatePaymentLink::class)->handleForUser($user, ApiTestHelpers::parsedBody()))->toThrow(AuthorizationException::class);
    expect(PaymentLink::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses panel creation during impersonation at the action level (plan §17.4)', function (): void {
    $user = actingAsTenantUser(tenantUser(ApiTestHelpers::readyTenant()));
    request()->setLaravelSession(app('session.store'));
    app(ImpersonationState::class)->start('01J8Z3Q6T4Y0V8KX2M1N5P7R9S', '01J8Z3Q6T4Y0V8KX2M1N5P7R9T');

    expect(fn () => app(CreatePaymentLink::class)->handleForUser($user, ApiTestHelpers::parsedBody()))->toThrow(AuthorizationException::class);
    expect(PaymentLink::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('reads the tenant a fixed number of times however many links are listed', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));

    $tenantQueries = static function (int $links) use ($tenant): int {
        PaymentLink::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->delete();

        foreach (range(1, $links) as $i) {
            ApiTestHelpers::link($tenant);
        }

        // A fresh request: nothing remembered from the previous render.
        app()->forgetInstance(TenantAccess::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListPaymentLinks::class)->assertCanSeeTableRecords(PaymentLink::query()->get());
        $count = count(array_filter(DB::getQueryLog(), static fn (array $entry): bool => str_contains((string) $entry['query'], 'from `tenants`')));
        DB::disableQueryLog();

        return $count;
    };

    $few = $tenantQueries(2);
    $many = $tenantQueries(8);

    expect($many)->toBe($few)->and($few)->toBeLessThanOrEqual(2);
});

it('searches the client reference by prefix', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $match = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['client_reference_id' => 'ORD-100', 'description' => 'First']));
    $inside = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['client_reference_id' => 'X-ORD-200', 'description' => 'Second']));
    $wildcard = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['client_reference_id' => 'ORDXYZ', 'description' => 'Third']));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    Livewire::test(ListPaymentLinks::class)
        ->searchTable('ORD-')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$inside, $wildcard])
        ->searchTable('ORD_')
        ->assertCanNotSeeTableRecords([$match, $inside, $wildcard]);
});
