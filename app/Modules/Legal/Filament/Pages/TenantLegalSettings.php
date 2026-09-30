<?php

declare(strict_types=1);

namespace App\Modules\Legal\Filament\Pages;

use App\Modules\Identity\Models\User;
use App\Modules\Legal\Actions\RemoveTenantLegalDocument;
use App\Modules\Legal\Actions\SaveTenantLegalDocument;
use App\Modules\Legal\Data\LegalDocument;
use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Filament\Concerns\ManagesLegalDocuments;
use App\Modules\Legal\Models\TenantLegalDocument;
use App\Modules\Legal\Services\TenantLegalDocuments;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "Legal" in the tenant panel (ADR-0056): the merchant's privacy notice and
 * terms, each a text or a link, shown to payers on the payment pages. Only
 * for `legal:manage` holders; changes are audited by the actions. A
 * suspended or closed tenant, and an impersonation session, only see it.
 */
final class TenantLegalSettings extends Page
{
    use ManagesLegalDocuments;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 85;

    protected static ?string $slug = 'settings/legal';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can('viewAny', TenantLegalDocument::class);
    }

    public static function getNavigationGroup(): string
    {
        return __('legal.navigation.group');
    }

    public function getSubheading(): string
    {
        return __('legal.page.tenant_subheading');
    }

    protected function currentDocument(LegalDocumentKind $kind): ?LegalDocument
    {
        return app(TenantLegalDocuments::class)->find($this->user()->tenant_id, $kind);
    }

    protected function saveDocument(LegalDocumentData $data): void
    {
        app(SaveTenantLegalDocument::class)->handle($this->user(), $data);
    }

    protected function removeDocument(LegalDocumentKind $kind): void
    {
        app(RemoveTenantLegalDocument::class)->handle($this->user(), $kind);
    }

    /** @return class-string */
    protected static function policyModel(): string
    {
        return TenantLegalDocument::class;
    }

    protected static function requiresReauthentication(): bool
    {
        return false;
    }

    protected function sectionDescription(LegalDocumentKind $kind): string
    {
        return __('legal.sections.tenant_'.$kind->value);
    }

    /** Without a privacy notice the checkout collects no payer data (ADR-0051). */
    protected function missingWarnings(): array
    {
        return [LegalDocumentKind::Privacy->value => (string) __('legal.status.no_privacy_warning')];
    }

    protected function removeHelp(LegalDocumentKind $kind): string
    {
        return $kind === LegalDocumentKind::Privacy ? __('legal.actions.remove_privacy_help') : __('legal.actions.remove_help');
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
