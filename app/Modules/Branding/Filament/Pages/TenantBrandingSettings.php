<?php

declare(strict_types=1);

namespace App\Modules\Branding\Filament\Pages;

use App\Modules\Branding\Actions\RemoveTenantLogo;
use App\Modules\Branding\Actions\UpdateTenantLogo;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Models\TenantLogo;
use App\Modules\Branding\Services\ImageNormalizer;
use App\Modules\Branding\Services\TenantLogos;
use App\Modules\Identity\Models\User;
use App\Support\Filament\DomainErrors;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * "Brand" in the tenant panel (ADR-0056 part B): the company logo shown to
 * payers at the top of the payment pages, a light variant and an optional
 * dark-theme one, each uploaded and removed on its own, with a preview on a
 * light and a dark page. The panels keep the platform logo.
 *
 * Only for `settings:manage` holders; changes are audited by the actions. A
 * suspended or closed tenant, and an impersonation session, only see it. No
 * business logic here: the upload goes to UpdateTenantLogo as raw bytes
 * (never stored on a disk), which checks and re-encodes it.
 */
final class TenantBrandingSettings extends Page
{
    private const string UPLOAD_FIELD = 'logo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?int $navigationSort = 84;

    protected static ?string $slug = 'settings/branding';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can('viewAny', TenantLogo::class);
    }

    public static function getNavigationGroup(): string
    {
        return __('branding.tenant.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('branding.tenant.title');
    }

    public function getTitle(): string
    {
        return __('branding.tenant.title');
    }

    public function getSubheading(): string
    {
        return __('branding.tenant.subheading');
    }

    public function content(Schema $schema): Schema
    {
        $logos = app(TenantLogos::class);
        $tenantId = $this->user()->tenant_id;
        $light = $logos->find($tenantId, LogoVariant::Light);
        $dark = $light !== null ? $logos->find($tenantId, LogoVariant::Dark) : null;
        $lightSrc = $light !== null ? self::dataUri($logos->content($tenantId, LogoVariant::Light)) : null;
        $darkSrc = $dark !== null ? self::dataUri($logos->content($tenantId, LogoVariant::Dark)) : null;

        return $schema->components([
            Section::make(__('branding.tenant.heading'))
                ->description(__('branding.tenant.description'))
                ->icon(Heroicon::OutlinedPhoto)
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        View::make('filament.branding.merchant-logo-preview')->viewData([
                            'dark' => false,
                            'src' => $lightSrc,
                            'width' => $light?->width,
                            'height' => $light?->height,
                            'plate' => false,
                            'fallback' => false,
                        ]),
                        View::make('filament.branding.merchant-logo-preview')->viewData([
                            'dark' => true,
                            'src' => $darkSrc ?? $lightSrc,
                            'width' => $dark->width ?? $light?->width,
                            'height' => $dark->height ?? $light?->height,
                            'plate' => $light !== null && $dark === null,
                            'fallback' => $light !== null && $dark === null,
                        ]),
                    ]),
                    Actions::make([
                        $this->uploadLightAction(),
                        $this->uploadDarkAction(),
                        $this->removeDarkAction(),
                        $this->removeLightAction(),
                    ]),
                ]),
        ]);
    }

    public function uploadLightAction(): Action
    {
        return $this->uploadAction(LogoVariant::Light);
    }

    public function uploadDarkAction(): Action
    {
        return $this->uploadAction(LogoVariant::Dark)
            // A dark variant alone is never shown: only once a logo exists.
            ->visible(fn (): bool => $this->has(LogoVariant::Light));
    }

    public function removeLightAction(): Action
    {
        return $this->removeAction(LogoVariant::Light);
    }

    public function removeDarkAction(): Action
    {
        return $this->removeAction(LogoVariant::Dark);
    }

    private function uploadAction(LogoVariant $variant): Action
    {
        $label = __('branding.tenant.variant.'.$variant->value);
        $help = __('branding.tenant.actions.'.($variant === LogoVariant::Dark ? 'upload_dark_help' : 'upload_help'), [
            'box_width' => ImageNormalizer::LOGO_MAX_WIDTH,
            'box_height' => ImageNormalizer::LOGO_MAX_HEIGHT,
            'max_mb' => 1,
            'max_px' => ImageNormalizer::MAX_SOURCE_PIXELS,
        ]);

        return Action::make('upload'.ucfirst($variant->value))
            ->label(fn (): string => __('branding.tenant.actions.'.($this->has($variant) ? 'replace_' : 'upload_').$variant->value))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color($variant === LogoVariant::Light ? 'primary' : 'gray')
            ->authorize('manage', TenantLogo::class)
            ->modalHeading(__('branding.tenant.actions.'.($variant === LogoVariant::Dark ? 'upload_dark' : 'upload_light')))
            ->modalDescription($help)
            ->modalSubmitActionLabel(__('branding.actions.upload_submit'))
            ->schema([
                FileUpload::make(self::UPLOAD_FIELD)
                    ->label(__('branding.fields.file'))
                    ->helperText(__('branding.fields.file_help', ['max_mb' => 1, 'max_px' => ImageNormalizer::MAX_SOURCE_PIXELS]))
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(ImageNormalizer::MAX_BYTES / 1024)
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data, Action $action) use ($variant, $label): void {
                $file = self::uploadedFile($data[self::UPLOAD_FIELD] ?? null);

                if ($file === null) {
                    throw ValidationException::withMessages([DomainErrors::fieldPath($action, self::UPLOAD_FIELD) => __('branding.errors.empty')]);
                }

                try {
                    app(UpdateTenantLogo::class)->handle($this->user(), $variant, (string) $file->get());
                } catch (InvalidImageException $e) {
                    throw ValidationException::withMessages([DomainErrors::fieldPath($action, self::UPLOAD_FIELD) => $e->rejection->message()]);
                } finally {
                    @unlink((string) $file->getRealPath());
                }

                $this->refreshContent();
                Notification::make()->success()->title(__('branding.tenant.notifications.updated', ['variant' => $label]))->send();
            });
    }

    private function removeAction(LogoVariant $variant): Action
    {
        $label = __('branding.tenant.variant.'.$variant->value);

        return Action::make('remove'.ucfirst($variant->value))
            ->label(__('branding.tenant.actions.remove_'.$variant->value))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->outlined()
            ->visible(fn (): bool => $this->has($variant))
            ->authorize('manage', TenantLogo::class)
            ->requiresConfirmation()
            ->modalHeading(__('branding.tenant.actions.remove_'.$variant->value.'_heading'))
            ->modalDescription(__('branding.tenant.actions.remove_'.$variant->value.'_help'))
            ->modalSubmitActionLabel(__('branding.tenant.actions.remove_'.$variant->value))
            ->action(function () use ($variant, $label): void {
                app(RemoveTenantLogo::class)->handle($this->user(), $variant);

                $this->refreshContent();
                Notification::make()->success()->title(__('branding.tenant.notifications.removed', ['variant' => $label]))->send();
            });
    }

    private function has(LogoVariant $variant): bool
    {
        return app(TenantLogos::class)->find($this->user()->tenant_id, $variant) !== null;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private static function dataUri(?string $png): ?string
    {
        return $png !== null ? 'data:image/png;base64,'.base64_encode($png) : null;
    }

    private static function uploadedFile(mixed $value): ?UploadedFile
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return $value instanceof UploadedFile ? $value : null;
    }

    /** A logo changed: rebuild the page content on this render. */
    private function refreshContent(): void
    {
        app(TenantLogos::class)->forget($this->user()->tenant_id);
        unset($this->cachedSchemas['content']);
    }
}
