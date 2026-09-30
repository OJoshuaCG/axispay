<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Services\ReauthenticationWindow;
use App\Modules\Legal\Actions\SavePlatformLegalDocument;
use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Filament\Pages\PlatformLegalSettings;
use App\Modules\Legal\Models\PlatformLegalDocument;
use App\Modules\Legal\Services\PlatformLegalDocuments;
use App\Modules\PlatformAdmin\Enums\PlatformPermission;
use App\Modules\PlatformAdmin\Enums\PlatformRole;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\startSession;

/*
 * Settings → Legal in the platform panel (ADR-0056): the platform's privacy
 * notice and terms, only for `platform:legal:manage` (superadmin), every
 * change inside the re-authentication window and audited in the platform
 * log, like Branding (ADR-0053).
 */

beforeEach(function (): void {
    startSession();
    Notification::fake();
});

function platformLegalAudit(AuditAction $action): int
{
    return AuditLog::query()->withoutGlobalScope(TenantScope::class)->whereNull('tenant_id')->where('action', $action->value)->count();
}

it('gives platform:legal:manage to superadmins only', function (): void {
    expect(PlatformRole::Superadmin->permissions())->toContain(PlatformPermission::LegalManage)
        ->and(PlatformRole::SupportReadonly->permissions())->not->toContain(PlatformPermission::LegalManage)
        ->and(platformAdmin(superadmin: false)->hasPlatformPermission(PlatformPermission::LegalManage))->toBeFalse();
});

it('is only reachable with platform:legal:manage, never by a tenant user', function (): void {
    actingAs(platformAdmin(superadmin: false), 'platform');
    get(adminUrl('/settings/legal'))->assertForbidden();

    actingAs(platformAdmin(), 'platform');
    get(adminUrl('/settings/legal'))->assertOk()->assertSee(__('legal.page.platform_subheading'));

    actingAsTenantUser(tenantUser());
    expect(PlatformLegalSettings::canAccess())->toBeFalse()
        ->and(tenantUser()->can('manage', PlatformLegalDocument::class))->toBeFalse();
});

it('saves the platform terms after re-authentication and audits it in the platform log', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();

    Livewire::test(PlatformLegalSettings::class)
        ->callAction('editTerms', data: ['format' => 'text', 'body' => "## Uso\n\nCondiciones del servicio."])
        ->assertHasNoActionErrors()
        ->assertSee('Condiciones del servicio.');

    $document = PlatformLegalDocument::query()->sole();

    expect($document->kind)->toBe(LegalDocumentKind::Terms)
        ->and($document->format)->toBe(LegalDocumentFormat::Text)
        ->and(app(PlatformLegalDocuments::class)->hasAny())->toBeTrue()
        ->and(platformLegalAudit(AuditAction::PlatformLegalDocumentUpdated))->toBe(1);
});

it('asks for the password when the re-authentication window is closed', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    session()->forget(ReauthenticationWindow::SESSION_KEY);

    Livewire::test(PlatformLegalSettings::class)
        ->callAction('editPrivacy', data: ['format' => 'url', 'url' => 'https://axispay.example/privacidad'])
        ->assertHasActionErrors(['current_password' => 'required']);

    expect(PlatformLegalDocument::query()->count())->toBe(0);

    $admin = platformAdmin();
    expect(fn () => app(SavePlatformLegalDocument::class)->handle($admin, LegalDocumentData::from(LegalDocumentKind::Privacy, LegalDocumentFormat::Url, null, 'https://axispay.example/privacidad')))
        ->toThrow(ReauthenticationRequiredException::class);
});

it('refuses support staff at the action level', function (): void {
    $support = actingAsPlatformAdmin(platformAdmin(superadmin: false));
    app(ReauthenticationWindow::class)->confirm();

    expect(fn () => app(SavePlatformLegalDocument::class)->handle($support, LegalDocumentData::from(LegalDocumentKind::Terms, LegalDocumentFormat::Text, 'Términos.', null)))
        ->toThrow(AuthorizationException::class);
    expect(PlatformLegalDocument::query()->count())->toBe(0);
});

it('removes a platform document and forgets the cached state', function (): void {
    actingAsPlatformAdmin(platformAdmin());
    app(ReauthenticationWindow::class)->confirm();

    Livewire::test(PlatformLegalSettings::class)
        ->callAction('editPrivacy', data: ['format' => 'url', 'url' => 'https://axispay.example/privacidad'])
        ->assertHasNoActionErrors()
        ->callAction('removePrivacy')
        ->assertHasNoActionErrors()
        ->assertActionHidden('removePrivacy');

    expect(PlatformLegalDocument::query()->count())->toBe(0)
        ->and(app(PlatformLegalDocuments::class)->hasAny())->toBeFalse()
        ->and(platformLegalAudit(AuditAction::PlatformLegalDocumentRemoved))->toBe(1);
});
