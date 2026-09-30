<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Access\Enums\TenantPermission;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Services\ImpersonationState;
use App\Modules\Legal\Actions\RemoveTenantLegalDocument;
use App\Modules\Legal\Actions\SaveTenantLegalDocument;
use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Filament\Pages\TenantLegalSettings;
use App\Modules\Legal\Models\TenantLegalDocument;
use App\Modules\Legal\Services\TenantLegalDocuments;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * Settings → Legal in the tenant panel (ADR-0056): the merchant's privacy
 * notice and terms, each a text or a link, managed with `legal:manage`,
 * audited in the tenant's log, read-only for suspended or closed tenants and
 * during impersonation, and never visible to another tenant.
 */

beforeEach(function (): void {
    startSession();
    Notification::fake();
});

/**
 * @return list<TenantLegalDocument>
 */
function tenantLegalDocuments(Tenant $tenant): array
{
    return app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): array => array_values(TenantLegalDocument::query()->orderBy('kind')->get()->all()));
}

function tenantLegalAudit(Tenant $tenant, AuditAction $action): int
{
    return AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('action', $action->value)->count();
}

// --- Permission and access ------------------------------------------------------

it('gives legal:manage to owners and admins only', function (): void {
    expect(SystemRole::Owner->permissions())->toContain(TenantPermission::LegalManage)
        ->and(SystemRole::Admin->permissions())->toContain(TenantPermission::LegalManage)
        ->and(SystemRole::Finance->permissions())->not->toContain(TenantPermission::LegalManage)
        ->and(SystemRole::IntegrationManager->permissions())->not->toContain(TenantPermission::LegalManage)
        ->and(SystemRole::LinkCreator->permissions())->not->toContain(TenantPermission::LegalManage)
        ->and(SystemRole::Viewer->permissions())->not->toContain(TenantPermission::LegalManage)
        ->and(TenantPermission::LegalManage->isSensitive())->toBeFalse();
});

it('opens for owners in English and Spanish, with the privacy warning while none is set', function (string $locale): void {
    app()->setLocale($locale);
    actingAsTenantUser(tenantUser(activeTenant()));

    Livewire::test(TenantLegalSettings::class)
        ->assertOk()
        ->assertSee(__('legal.page.title'))
        ->assertSee(__('legal.kind.privacy'))
        ->assertSee(__('legal.kind.terms'))
        ->assertSee(__('legal.status.no_privacy_warning'))
        ->assertActionVisible('editPrivacy')
        ->assertActionHidden('removePrivacy');
})->with(['en', 'es']);

it('is only reachable with legal:manage', function (): void {
    $tenant = activeTenant();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Viewer]));
    expect(TenantLegalSettings::canAccess())->toBeFalse();
    get(appUrl('/settings/legal'))->assertForbidden();

    actingAsTenantUser(tenantUser($tenant, [SystemRole::Admin]));
    expect(TenantLegalSettings::canAccess())->toBeTrue();
    get(appUrl('/settings/legal'))->assertOk()->assertSee(__('legal.page.title'));
});

// --- Saving and removing ---------------------------------------------------------

it('saves a text privacy notice, previews it safely and audits it without the text', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantLegalSettings::class)
        ->callAction('editPrivacy', data: ['format' => 'text', 'body' => "# Aviso\r\n\r\nTratamos tus datos.<script>alert(1)</script>"])
        ->assertHasNoActionErrors()
        ->assertSee('Tratamos tus datos.')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertActionVisible('removePrivacy');

    [$document] = tenantLegalDocuments($tenant);
    $audit = AuditLog::query()->withoutGlobalScope(TenantScope::class)->where('action', AuditAction::LegalDocumentUpdated->value)->sole();

    expect($document->kind)->toBe(LegalDocumentKind::Privacy)
        ->and($document->format)->toBe(LegalDocumentFormat::Text)
        ->and($document->body)->toBe("# Aviso\n\nTratamos tus datos.<script>alert(1)</script>")
        ->and($document->url)->toBeNull()
        ->and($audit->tenant_id)->toBe($tenant->id)
        ->and($audit->changes)->toMatchArray(['kind' => 'privacy', 'format_after' => 'text', 'body_after_hash' => hash('sha256', (string) $document->body)])
        ->and(json_encode($audit->changes))->not->toContain('Tratamos');
});

it('switches a document from text to a link and keeps only the link', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    Checkout::legalDocument($tenant, LegalDocumentKind::Terms, body: 'Términos.');

    Livewire::test(TenantLegalSettings::class)
        ->callAction('editTerms', data: ['format' => 'url', 'url' => 'https://tienda.example/terminos'])
        ->assertHasNoActionErrors()
        ->assertSee('https://tienda.example/terminos');

    [$document] = tenantLegalDocuments($tenant);

    expect($document->format)->toBe(LegalDocumentFormat::Url)
        ->and($document->url)->toBe('https://tienda.example/terminos')
        ->and($document->body)->toBeNull()
        ->and(tenantLegalAudit($tenant, AuditAction::LegalDocumentUpdated))->toBe(1);
});

it('refuses an unsafe or malformed link on the url field', function (string $url): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantLegalSettings::class)
        ->callAction('editPrivacy', data: ['format' => 'url', 'url' => $url])
        ->assertHasActionErrors(['url']);

    expect(tenantLegalDocuments($tenant))->toBe([]);
})->with([
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,hi',
    'ftp' => 'ftp://tienda.example/aviso',
    'relative' => '/aviso',
    'credentials' => 'https://user:secret@tienda.example/aviso',
]);

it('refuses an empty or too long text on the body field', function (string $body): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));

    Livewire::test(TenantLegalSettings::class)
        ->callAction('editPrivacy', data: ['format' => 'text', 'body' => $body])
        ->assertHasActionErrors(['body']);

    expect(tenantLegalDocuments($tenant))->toBe([]);
})->with([
    'empty' => '   ',
    'too long' => str_repeat('a', LegalDocumentData::MAX_BODY_LENGTH + 1),
]);

it('does not audit saving the same document again', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $data = LegalDocumentData::from(LegalDocumentKind::Privacy, LegalDocumentFormat::Url, null, 'https://tienda.example/aviso');

    app(SaveTenantLegalDocument::class)->handle($owner, $data);
    app(SaveTenantLegalDocument::class)->handle($owner, $data);

    expect(tenantLegalDocuments($tenant))->toHaveCount(1)
        ->and(tenantLegalAudit($tenant, AuditAction::LegalDocumentUpdated))->toBe(1);
});

it('removes a document, audits it once, and the checkout stops seeing it', function (): void {
    $tenant = activeTenant();
    actingAsTenantUser(tenantUser($tenant));
    Checkout::legalDocument($tenant, LegalDocumentKind::Privacy, url: 'https://tienda.example/aviso');

    expect(app(TenantLegalDocuments::class)->hasPrivacyNotice($tenant->id))->toBeTrue();

    Livewire::test(TenantLegalSettings::class)
        ->callAction('removePrivacy')
        ->assertHasNoActionErrors()
        ->assertSee(__('legal.status.no_privacy_warning'));

    expect(tenantLegalDocuments($tenant))->toBe([])
        ->and(app(TenantLegalDocuments::class)->hasPrivacyNotice($tenant->id))->toBeFalse()
        ->and(tenantLegalAudit($tenant, AuditAction::LegalDocumentRemoved))->toBe(1);

    // Removing what is not set changes nothing.
    expect(app(RemoveTenantLegalDocument::class)->handle(tenantUser($tenant), LegalDocumentKind::Privacy))->toBeFalse()
        ->and(tenantLegalAudit($tenant, AuditAction::LegalDocumentRemoved))->toBe(1);
});

// --- Read-only states --------------------------------------------------------------

it('is read-only for a suspended or closed tenant', function (TenantStatus $status): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    $tenant->forceFill(['status' => $status])->save();

    Livewire::test(TenantLegalSettings::class)->assertOk()->assertActionHidden('editPrivacy');

    expect(fn () => app(SaveTenantLegalDocument::class)->handle($owner, LegalDocumentData::from(LegalDocumentKind::Privacy, LegalDocumentFormat::Text, 'Aviso.', null)))
        ->toThrow(AuthorizationException::class);
    expect(tenantLegalDocuments($tenant))->toBe([]);
})->with([TenantStatus::Suspended, TenantStatus::Closed]);

it('refuses changes during impersonation (plan 17.4)', function (): void {
    $tenant = activeTenant();
    $owner = actingAsTenantUser(tenantUser($tenant));
    request()->setLaravelSession(app('session.store'));
    app(ImpersonationState::class)->start('01J8Z3Q6T4Y0V8KX2M1N5P7R9S', '01J8Z3Q6T4Y0V8KX2M1N5P7R9T');

    expect($owner->can('viewAny', TenantLegalDocument::class))->toBeTrue()
        ->and($owner->can('manage', TenantLegalDocument::class))->toBeFalse();
    expect(fn () => app(RemoveTenantLegalDocument::class)->handle($owner, LegalDocumentKind::Privacy))->toThrow(AuthorizationException::class);
});

// --- Isolation (rules.md rule 3) ---------------------------------------------------

it('never shows or changes another tenant\'s documents', function (): void {
    [$a, $b] = [activeTenant(), activeTenant()];
    Checkout::legalDocument($b, LegalDocumentKind::Privacy, body: 'Aviso privado de B.');
    Checkout::legalDocument($b, LegalDocumentKind::Terms, url: 'https://b.example/terminos');

    actingAsTenantUser(tenantUser($a));

    Livewire::test(TenantLegalSettings::class)
        ->assertDontSee('Aviso privado de B.')
        ->assertDontSee('https://b.example/terminos')
        ->assertActionHidden('removePrivacy')
        ->callAction('editPrivacy', data: ['format' => 'url', 'url' => 'https://a.example/aviso'])
        ->assertHasNoActionErrors();

    $bDocuments = tenantLegalDocuments($b);

    expect(tenantLegalDocuments($a))->toHaveCount(1)
        ->and($bDocuments)->toHaveCount(2)
        ->and($bDocuments[0]->body)->toBe('Aviso privado de B.')
        ->and(tenantLegalAudit($b, AuditAction::LegalDocumentUpdated))->toBe(0);

    // Reading tenant B's documents from tenant A's context finds nothing.
    expect(app(TenantLegalDocuments::class)->all($b->id))->toBe([]);
});
