<?php

declare(strict_types=1);

namespace App\Modules\Branding\Filament\Pages;

use App\Modules\Branding\Actions\ChangeBrandDisplayMode;
use App\Modules\Branding\Actions\RemovePlatformFavicon;
use App\Modules\Branding\Actions\RemovePlatformLogo;
use App\Modules\Branding\Actions\UpdatePlatformFavicon;
use App\Modules\Branding\Actions\UpdatePlatformLogo;
use App\Modules\Branding\Enums\BrandDisplayMode;
use App\Modules\Branding\Enums\FaviconSize;
use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Exceptions\InvalidImageException;
use App\Modules\Branding\Models\PlatformFavicon;
use App\Modules\Branding\Models\PlatformLogo;
use App\Modules\Branding\Services\ImageNormalizer;
use App\Modules\Branding\Services\PlatformBrand;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\PlatformAdmin\Enums\PlatformPermission;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Support\Filament\DomainErrors;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
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
 * "Branding" (ADR-0053): the platform logo (light and optional dark
 * variant), what the brand shows (logo and name, logo only, name only) and
 * the favicon.
 * Only for platform admins holding `platform:branding:manage`; every change
 * needs the re-authentication window and is audited by its action.
 *
 * No business logic here: the upload goes to UpdatePlatformLogo as raw bytes
 * (never stored on a disk), which checks and re-encodes it.
 */
final class BrandingSettings extends Page
{
    private const string UPLOAD_FIELD = 'logo';

    private const string FAVICON_FIELD = 'favicon';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static ?int $navigationSort = 85;

    protected static ?string $slug = 'settings/branding';

    public static function canAccess(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof PlatformAdmin && $admin->hasPlatformPermission(PlatformPermission::BrandingManage);
    }

    public static function getNavigationLabel(): string
    {
        return __('branding.page.title');
    }

    public function getTitle(): string
    {
        return __('branding.page.title');
    }

    public function getSubheading(): string
    {
        return __('branding.page.subheading');
    }

    public function content(Schema $schema): Schema
    {
        $brand = app(PlatformBrand::class);

        return $schema->components([
            Section::make(__('branding.logo.heading'))
                ->description(__('branding.logo.description'))
                ->icon(Heroicon::OutlinedPhoto)
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        View::make('filament.branding.logo-preview')->viewData([
                            'variant' => LogoVariant::Light,
                            'url' => $brand->logoUrl(LogoVariant::Light),
                            'fallback' => false,
                        ]),
                        View::make('filament.branding.logo-preview')->viewData([
                            'variant' => LogoVariant::Dark,
                            'url' => $brand->logoUrl(LogoVariant::Dark),
                            'fallback' => $brand->hasLogo() && ! $brand->hasVariant(LogoVariant::Dark),
                        ]),
                    ]),
                ]),
            Section::make(__('branding.display.heading'))
                ->description(__('branding.display.description'))
                ->icon(Heroicon::OutlinedEye)
                ->schema([
                    TextEntry::make('mode')
                        ->label(__('branding.fields.mode'))
                        ->state($brand->configuredMode()->label())
                        ->helperText($brand->hasLogo() ? null : (string) __('branding.display.no_logo')),
                ]),
            Section::make(__('branding.favicon.heading'))
                ->description(__('branding.favicon.description'))
                ->icon(Heroicon::OutlinedSquare2Stack)
                ->schema([
                    View::make('filament.branding.favicon-preview')->viewData([
                        'urls' => $brand->hasFavicon()
                            ? array_map(static fn (FaviconSize $size): ?string => $brand->faviconUrl($size), array_combine(FaviconSize::pixels(), FaviconSize::cases()))
                            : [],
                    ]),
                    Actions::make([$this->uploadFaviconAction(), $this->removeFaviconAction()]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->uploadAction(),
            $this->displayModeAction(),
            $this->removeAction(),
        ];
    }

    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('branding.actions.upload'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->authorize('manage', PlatformLogo::class)
            ->modalHeading(__('branding.actions.upload_heading'))
            ->modalDescription(__('branding.actions.upload_help', ['max_mb' => 1, 'max_px' => ImageNormalizer::MAX_SOURCE_PIXELS]))
            ->modalSubmitActionLabel(__('branding.actions.upload_submit'))
            ->schema([
                Select::make('variant')
                    ->label(__('branding.fields.variant'))
                    ->options(fn (): array => $this->uploadableVariants())
                    ->default(LogoVariant::Light->value)
                    ->helperText(__('branding.fields.variant_help'))
                    ->selectablePlaceholder(false)
                    ->required(),
                FileUpload::make(self::UPLOAD_FIELD)
                    ->label(__('branding.fields.file'))
                    ->helperText(__('branding.fields.file_help', ['max_mb' => 1, 'max_px' => ImageNormalizer::MAX_SOURCE_PIXELS]))
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(ImageNormalizer::MAX_BYTES / 1024)
                    ->storeFiles(false)
                    ->required(),
                Reauthentication::field(),
            ])
            ->action(function (array $data, Action $action): void {
                $variant = LogoVariant::tryFrom(is_string($data['variant'] ?? null) ? $data['variant'] : '') ?? LogoVariant::Light;
                $file = self::uploadedFile($data[self::UPLOAD_FIELD] ?? null);

                if ($file === null) {
                    throw ValidationException::withMessages([DomainErrors::fieldPath($action, self::UPLOAD_FIELD) => __('branding.errors.empty')]);
                }

                try {
                    $this->run($data, fn (PlatformAdmin $admin): PlatformLogo => app(UpdatePlatformLogo::class)->handle($admin, $variant, (string) $file->get()));
                } catch (InvalidImageException $e) {
                    throw ValidationException::withMessages([DomainErrors::fieldPath($action, self::UPLOAD_FIELD) => $e->rejection->message()]);
                } finally {
                    @unlink((string) $file->getRealPath());
                }

                $this->refreshContent();
                Notification::make()->success()->title(__('branding.notifications.updated', ['variant' => $variant->label()]))->send();
            });
    }

    public function displayModeAction(): Action
    {
        return Action::make('displayMode')
            ->label(__('branding.actions.display_mode'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->authorize('manage', PlatformLogo::class)
            ->modalHeading(__('branding.actions.display_mode_heading'))
            ->modalSubmitActionLabel(__('branding.actions.save'))
            ->fillForm(fn (): array => ['mode' => app(PlatformBrand::class)->configuredMode()->value])
            ->schema([
                Select::make('mode')
                    ->label(__('branding.fields.mode'))
                    ->options(BrandDisplayMode::options())
                    ->helperText(__('branding.fields.mode_help'))
                    ->selectablePlaceholder(false)
                    ->required(),
                Reauthentication::field(),
            ])
            ->action(function (array $data): void {
                $mode = BrandDisplayMode::tryFrom(is_string($data['mode'] ?? null) ? $data['mode'] : '') ?? BrandDisplayMode::LogoAndName;

                $this->run($data, fn (PlatformAdmin $admin): BrandDisplayMode => app(ChangeBrandDisplayMode::class)->handle($admin, $mode));

                $this->refreshContent();
                Notification::make()->success()->title(__('branding.notifications.mode_changed', ['mode' => $mode->label()]))->send();
            });
    }

    public function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('branding.actions.remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->outlined()
            ->visible(fn (): bool => app(PlatformBrand::class)->hasLogo())
            ->authorize('manage', PlatformLogo::class)
            ->requiresConfirmation()
            ->modalHeading(__('branding.actions.remove_heading'))
            ->modalDescription(__('branding.actions.remove_help'))
            ->modalSubmitActionLabel(__('branding.actions.remove'))
            ->schema([
                Select::make('variant')
                    ->label(__('branding.fields.variant'))
                    ->options(fn (): array => $this->removableVariants())
                    ->default(LogoVariant::Light->value)
                    ->selectablePlaceholder(false)
                    ->required(),
                Reauthentication::field(),
            ])
            ->action(function (array $data): void {
                $variant = LogoVariant::tryFrom(is_string($data['variant'] ?? null) ? $data['variant'] : '') ?? LogoVariant::Light;

                $this->run($data, fn (PlatformAdmin $admin): array => app(RemovePlatformLogo::class)->handle($admin, $variant));

                $this->refreshContent();
                Notification::make()->success()->title(__('branding.notifications.removed', ['variant' => $variant->label()]))->send();
            });
    }

    public function uploadFaviconAction(): Action
    {
        return Action::make('uploadFavicon')
            ->label(__('branding.favicon.upload'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->authorize('manage', PlatformFavicon::class)
            ->modalHeading(__('branding.favicon.upload_heading'))
            ->modalDescription(__('branding.favicon.upload_help', ['max_mb' => 1, 'min_px' => ImageNormalizer::ICON_MIN_SOURCE_PIXELS, 'max_px' => ImageNormalizer::MAX_SOURCE_PIXELS]))
            ->modalSubmitActionLabel(__('branding.actions.upload_submit'))
            ->schema([
                FileUpload::make(self::FAVICON_FIELD)
                    ->label(__('branding.fields.file'))
                    ->helperText(__('branding.favicon.file_help', ['max_mb' => 1, 'min_px' => ImageNormalizer::ICON_MIN_SOURCE_PIXELS, 'max_px' => ImageNormalizer::MAX_SOURCE_PIXELS]))
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(ImageNormalizer::MAX_BYTES / 1024)
                    ->storeFiles(false)
                    ->required(),
                Reauthentication::field(),
            ])
            ->action(function (array $data, Action $action): void {
                $file = self::uploadedFile($data[self::FAVICON_FIELD] ?? null);

                if ($file === null) {
                    throw ValidationException::withMessages([DomainErrors::fieldPath($action, self::FAVICON_FIELD) => __('branding.errors.empty')]);
                }

                try {
                    $this->run($data, fn (PlatformAdmin $admin): array => app(UpdatePlatformFavicon::class)->handle($admin, (string) $file->get()));
                } catch (InvalidImageException $e) {
                    throw ValidationException::withMessages([DomainErrors::fieldPath($action, self::FAVICON_FIELD) => $e->rejection->message()]);
                } finally {
                    @unlink((string) $file->getRealPath());
                }

                $this->refreshContent();
                Notification::make()->success()->title(__('branding.favicon.updated'))->send();
            });
    }

    public function removeFaviconAction(): Action
    {
        return Action::make('removeFavicon')
            ->label(__('branding.favicon.remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->outlined()
            ->visible(fn (): bool => app(PlatformBrand::class)->hasFavicon())
            ->authorize('manage', PlatformFavicon::class)
            ->requiresConfirmation()
            ->modalHeading(__('branding.favicon.remove_heading'))
            ->modalDescription(__('branding.favicon.remove_help'))
            ->modalSubmitActionLabel(__('branding.favicon.remove'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data): void {
                $this->run($data, fn (PlatformAdmin $admin): bool => app(RemovePlatformFavicon::class)->handle($admin));

                $this->refreshContent();
                Notification::make()->success()->title(__('branding.favicon.removed'))->send();
            });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Re-authentication, then the domain action.
     *
     * @template TResult
     *
     * @param  array<mixed>  $data
     * @param  callable(PlatformAdmin): TResult  $callback
     * @return TResult
     */
    private function run(array $data, callable $callback): mixed
    {
        $admin = Filament::auth()->user();
        abort_unless($admin instanceof PlatformAdmin, 403);

        try {
            Reauthentication::confirm($data);

            return $callback($admin);
        } catch (ReauthenticationRequiredException) {
            DomainErrors::stop(__('branding.errors.reauthentication_required'));
        }
    }

    /**
     * The dark variant only once a light logo exists: a dark logo alone is
     * never shown.
     *
     * @return array<string, string>
     */
    private function uploadableVariants(): array
    {
        $options = [LogoVariant::Light->value => LogoVariant::Light->label()];

        if (app(PlatformBrand::class)->hasLogo()) {
            $options[LogoVariant::Dark->value] = LogoVariant::Dark->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function removableVariants(): array
    {
        $brand = app(PlatformBrand::class);
        $options = [];

        foreach (LogoVariant::cases() as $variant) {
            if ($brand->hasVariant($variant)) {
                $options[$variant->value] = $variant === LogoVariant::Light
                    ? __('branding.fields.remove_light')
                    : $variant->label();
            }
        }

        return $options;
    }

    private static function uploadedFile(mixed $value): ?UploadedFile
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return $value instanceof UploadedFile ? $value : null;
    }

    /** The brand changed: rebuild the page content on this render. */
    private function refreshContent(): void
    {
        app(PlatformBrand::class)->forget();
        unset($this->cachedSchemas['content']);
    }
}
