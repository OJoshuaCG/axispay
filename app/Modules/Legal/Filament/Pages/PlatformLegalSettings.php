<?php

declare(strict_types=1);

namespace App\Modules\Legal\Filament\Pages;

use App\Modules\Legal\Actions\RemovePlatformLegalDocument;
use App\Modules\Legal\Actions\SavePlatformLegalDocument;
use App\Modules\Legal\Data\LegalDocument;
use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Filament\Concerns\ManagesLegalDocuments;
use App\Modules\Legal\Models\PlatformLegalDocument;
use App\Modules\Legal\Services\PlatformLegalDocuments;
use App\Modules\PlatformAdmin\Enums\PlatformPermission;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * "Legal" in the platform panel (ADR-0056): the platform's privacy notice
 * and terms, each a text or a link, shown on the pay host's `/legal` page.
 * Only for platform admins holding `platform:legal:manage`; every change
 * needs the re-authentication window and is audited by its action, like
 * Branding (ADR-0053).
 */
final class PlatformLegalSettings extends Page
{
    use ManagesLegalDocuments;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 86;

    protected static ?string $slug = 'settings/legal';

    public static function canAccess(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof PlatformAdmin && $admin->hasPlatformPermission(PlatformPermission::LegalManage);
    }

    public function getSubheading(): string
    {
        return __('legal.page.platform_subheading');
    }

    protected function currentDocument(LegalDocumentKind $kind): ?LegalDocument
    {
        return app(PlatformLegalDocuments::class)->find($kind);
    }

    protected function saveDocument(LegalDocumentData $data): void
    {
        app(SavePlatformLegalDocument::class)->handle($this->admin(), $data);
    }

    protected function removeDocument(LegalDocumentKind $kind): void
    {
        app(RemovePlatformLegalDocument::class)->handle($this->admin(), $kind);
    }

    /** @return class-string */
    protected static function policyModel(): string
    {
        return PlatformLegalDocument::class;
    }

    protected static function requiresReauthentication(): bool
    {
        return true;
    }

    protected function sectionDescription(LegalDocumentKind $kind): string
    {
        return __('legal.sections.platform_'.$kind->value);
    }

    protected function missingWarnings(): array
    {
        return [];
    }

    protected function removeHelp(LegalDocumentKind $kind): string
    {
        return __('legal.actions.remove_help');
    }

    private function admin(): PlatformAdmin
    {
        $admin = Filament::auth()->user();
        abort_unless($admin instanceof PlatformAdmin, 403);

        return $admin;
    }
}
