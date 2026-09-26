<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource;
use App\Modules\PaymentLinks\Services\PaymentLinkUrl;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Tenancy\Http\Middleware\ApplyTenantTimezone;
use Filament\Actions\Action;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiTestHelpers;

use function Pest\Laravel\get;

/**
 * UI review of the payment link screens (ADR-0049).
 */
it('shows the amount as heading, the description as subheading and a short breadcrumb title', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['amount_minor' => 1_250_000, 'currency' => CurrencyCode::MXN, 'description' => str_repeat('Suscripción anual ', 12)]));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    app()->setLocale('es');

    $page = Livewire::test(ViewPaymentLink::class, ['record' => $link->id])->instance();
    assert($page instanceof ViewPaymentLink);

    expect($page->getTitle())->toBe('Link de pago')
        ->and($page->getHeading()->toHtml())->toBe('<span class="amount" lang="es-MX">12,500.00'."\u{00A0}".'MXN</span>')
        ->and(mb_strlen($page->getSubheading()))->toBeLessThanOrEqual(143)
        ->and(mb_strlen(PaymentLinkResource::getRecordTitle($link)))->toBeLessThanOrEqual(43);
});

it('offers copy and cancel only while the link can be paid, and hides the share URL after', function (string $state, bool $shareable): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => match ($state) {
        'active' => $f,
        'expired' => $f->expired(),
        default => $f->canceled()->state(['cancel_reason' => 'El cliente pagó por transferencia']),
    });
    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));
    app()->setLocale('es');

    $component = Livewire::test(ViewPaymentLink::class, ['record' => $link->id]);
    $shareable ? $component->assertActionVisible('copyLink')->assertActionVisible('cancel')->assertSee(PaymentLinkUrl::for($link))
        : $component->assertActionHidden('copyLink')->assertActionHidden('cancel')->assertDontSee(PaymentLinkUrl::for($link));

    if ($state === 'expired') {
        $component->assertSee('Venció el')->assertSee('Ya no acepta pagos');
    }

    if ($state === 'canceled') {
        $component->assertSee('Cancelado el')->assertSee('El cliente pagó por transferencia');
    }
})->with([['active', true], ['expired', false], ['canceled', false]]);

it('hides empty detail rows', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    Livewire::test(ViewPaymentLink::class, ['record' => $link->id])
        ->assertDontSee(__('payment_links.fields.paid_at'))
        ->assertDontSee(__('payment_links.fields.cancel_reason'))
        ->assertDontSee(__('payment_links.fields.refund_status'))
        ->assertDontSee(__('payment_links.fields.metadata'));
});

it('explains the consequences in the cancel dialog and keeps the link by default', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['amount_minor' => 45_000, 'currency' => CurrencyCode::MXN, 'description' => 'Clase de cocina']));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::LinkCreator]));
    app()->setLocale('es');

    $component = Livewire::test(ViewPaymentLink::class, ['record' => $link->id]);
    $component->mountAction('cancel');
    $component->assertMountedActionModalSee('Se cancelará el link de 450.00'."\u{00A0}".'MXN («Clase de cocina»)', false);
    $component->assertMountedActionModalSee('Mantener link');
});

it('uses a mobile summary column and moves the others to wider screens', function (): void {
    actingAsTenantUser(tenantUser(ApiTestHelpers::readyTenant(), [SystemRole::Viewer]));

    $table = ApiTestHelpers::tableOf(ListPaymentLinks::class);

    expect($table->getColumn('summary')?->getHiddenFrom())->toBe('md')
        ->and($table->getColumn('summary')?->getLabel())->toBe(__('payment_links.fields.link'))
        ->and($table->getColumn('status')?->getVisibleFrom())->toBe('md')
        ->and($table->getColumn('client_reference_id')?->getVisibleFrom())->toBe('lg')
        ->and($table->getColumn('created_at')?->getVisibleFrom())->toBe('xl');
});

it('shows a create button in the empty state for users who can create', function (): void {
    actingAsTenantUser(tenantUser(ApiTestHelpers::readyTenant(), [SystemRole::LinkCreator]));

    Livewire::test(ListPaymentLinks::class)->assertTableEmptyStateActionsExistInOrder(['createFromEmptyState']);

    actingAsTenantUser(tenantUser(ApiTestHelpers::readyTenant(), [SystemRole::Viewer]));
    $actions = ApiTestHelpers::tableOf(ListPaymentLinks::class)->getEmptyStateActions();
    expect(array_filter($actions, static fn ($a): bool => $a instanceof Action && $a->isVisible()))->toBe([]);
});

it('renders the mobile summary with amount and expiry in the tenant time zone', function (): void {
    Carbon::setTestNow('2026-09-26 18:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    $tenant->forceFill(['timezone' => 'America/Mexico_City'])->save();
    ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['amount_minor' => 150_000, 'currency' => CurrencyCode::MXN, 'expires_at' => '2026-09-29 18:00:00']));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    get(appUrl('/payment-links?lang=es'))
        ->assertOk()
        ->assertSee('1,500.00'."\u{00A0}".'MXN', false)
        ->assertSee('Vence 29 sep. 2026, 12:00', false);
});

it('sets the panel time zone from the tenant', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $tenant->forceFill(['timezone' => 'America/Tijuana'])->save();
    actingAsTenantUser(tenantUser($tenant));

    app(ApplyTenantTimezone::class)->handle(Request::create('/'), static fn (): Response => new Response);

    expect(FilamentTimezone::get())->toBe('America/Tijuana');
});

it('keeps every action form free of native browser validation', function (): void {
    expect(Action::make('probe')->getExtraModalWindowAttributes())->toHaveKey('novalidate', true);
});

it('orders the desktop columns description, amount, status, reference, expiry, created, and filters in a modal', function (): void {
    actingAsTenantUser(tenantUser(ApiTestHelpers::readyTenant(), [SystemRole::Viewer]));
    $table = ApiTestHelpers::tableOf(ListPaymentLinks::class);

    expect(array_keys($table->getColumns()))->toBe(['summary', 'description', 'amount_minor', 'status', 'client_reference_id', 'expires_at', 'created_at'])
        ->and($table->getFiltersLayout())->toBe(FiltersLayout::Modal);
});

it('shows the expiry as a date with the relative time as text below', function (): void {
    Carbon::setTestNow('2026-09-26 18:00:00');
    $tenant = ApiTestHelpers::readyTenant();
    $tenant->forceFill(['timezone' => 'America/Mexico_City'])->save();
    $link = ApiTestHelpers::link($tenant, state: static fn ($f) => $f->state(['expires_at' => '2026-09-29 18:00:00']));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    get(appUrl('/payment-links/'.$link->id.'?lang=es'))
        ->assertOk()
        ->assertSee('29 sep. 2026, 12:00', false)
        ->assertSee('en 3 días', false);
});

it('copies the link ID with a real button, not only a click on the text', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    get(appUrl('/payment-links/'.$link->id))
        ->assertOk()
        ->assertSee(__('payment_links.actions.copy_id'))
        ->assertSee(e(__('payment_links.copy_failed')), false);
});

it('marks formatted amounts with the language tag of their number format', function (): void {
    $tenant = ApiTestHelpers::readyTenant();
    ApiTestHelpers::link($tenant);
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));

    get(appUrl('/payment-links?lang=en'))->assertOk()->assertSee('lang="en-US"', false);
    expect(Blade::render('<x-amount :value="5" currency="EUR" locale="de_DE" />'))->toContain('lang="de-DE"');
});

it('explains the panel amount format when an amount is refused', function (string $locale, string $expected): void {
    app()->setLocale($locale);
    actingAsTenantUser(tenantUser(ApiTestHelpers::readyTenant(), [SystemRole::LinkCreator]));

    Livewire::test(ListPaymentLinks::class)
        ->callAction('create', data: ['amount' => '1.001', 'currency' => 'USD', 'description' => 'x'])
        ->assertHasActionErrors(['amount' => $expected]);
})->with([
    ['es', 'Escriba el monto con hasta dos decimales, por ejemplo 1,500.00.'],
    ['en', 'Enter the amount with up to two decimals, for example 1,500.00.'],
]);

it('uses Spanish wording for resetting filters and columns', function (): void {
    app()->setLocale('es');

    expect(__('filament-tables::table.filters.actions.reset.label'))->toBe('Restablecer filtros')
        ->and(__('filament-tables::table.column_manager.actions.reset.label'))->toBe('Restablecer columnas')
        ->and(__('filament-tables::table.filters.actions.apply.label'))->toBe('Aplicar filtros');
});
