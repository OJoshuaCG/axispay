<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Filament\Pages;

use App\Modules\Fx\Enums\FxMode;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Money\ExchangeRate;
use App\Modules\Tenancy\Actions\UpdateTenantPaymentSettings;
use App\Modules\Tenancy\Data\TenantPaymentSettingsData;
use App\Modules\Tenancy\Data\TenantSettings;
use App\Modules\Tenancy\Exceptions\InvalidPaymentSettingsException;
use App\Modules\Tenancy\Services\TenantAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * "Payment settings" in the tenant panel (ADR-0063, ADR-0048): the currency
 * conversion of USD links paid with Mexican cards (on or off, `fixed` or
 * `banxico_fix`, the tenant's fixed rate, the markup and how long a quote
 * lasts) and the default and maximum expiration of links created without an
 * expiry. These are the values that until now could only be edited in the
 * database.
 *
 * Only for `settings:manage` holders; a suspended or closed tenant, and an
 * impersonation session, only see it. No business logic here: the form
 * goes to UpdateTenantPaymentSettings, which checks the platform limits,
 * keeps the rest of the settings document and audits the change.
 *
 * @property-read Schema $form
 */
final class TenantPaymentSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 83;

    protected static ?string $slug = 'settings/payments';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can('viewAny', TenantSettings::class);
    }

    public static function getNavigationGroup(): string
    {
        return __('fx.settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('fx.settings.title');
    }

    public function getTitle(): string
    {
        return __('fx.settings.title');
    }

    public function getSubheading(): string
    {
        return __('fx.settings.subheading');
    }

    public function mount(): void
    {
        $settings = app(TenantAccess::class)->settings($this->user()->tenant_id);

        $this->form->fill([
            'fx_conversion_enabled' => $settings->fxConversionEnabled,
            'fx_default_mode' => $settings->fxDefaultMode->value,
            'fx_fixed_rate' => $settings->fxFixedRate?->toString(),
            'fx_markup_bps' => $settings->fxMarkupBps,
            'fx_quote_validity_minutes' => $settings->fxQuoteValidityMinutes,
            'links_default_expiration_hours' => $settings->defaultExpirationHours,
            'links_max_expiration_hours' => $settings->maxExpirationHours,
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->canManage());
    }

    public function form(Schema $schema): Schema
    {
        $platformMaxHours = config()->integer('axispay.limits.max_expiration_hours');

        return $schema->components([
            Section::make(__('fx.settings.fx.heading'))
                ->description(__('fx.settings.fx.description'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->schema([
                    Toggle::make('fx_conversion_enabled')
                        ->label(__('fx.settings.fx.enabled'))
                        ->helperText(__('fx.settings.fx.enabled_help'))
                        ->live(),
                    Radio::make('fx_default_mode')
                        ->label(__('fx.settings.fx.mode'))
                        ->options([
                            FxMode::Fixed->value => __('fx.settings.fx.mode_fixed'),
                            FxMode::BanxicoFix->value => __('fx.settings.fx.mode_banxico_fix'),
                        ])
                        ->descriptions([
                            FxMode::Fixed->value => __('fx.settings.fx.mode_fixed_help'),
                            FxMode::BanxicoFix->value => __('fx.settings.fx.mode_banxico_fix_help'),
                        ])
                        ->required()
                        ->live()
                        ->visible(fn (Get $get): bool => $get('fx_conversion_enabled') === true),
                    TextInput::make('fx_fixed_rate')
                        ->label(__('fx.settings.fx.fixed_rate'))
                        ->helperText(__('fx.settings.fx.fixed_rate_help'))
                        ->placeholder('20.000000')
                        ->regex('/^(0|[1-9][0-9]{0,11})(\.[0-9]{1,6})?$/D')
                        ->validationMessages(['regex' => __('fx.settings.errors.fixed_rate_format')])
                        ->extraInputAttributes(['class' => 'font-numeric', 'inputmode' => 'decimal'])
                        ->required(fn (Get $get): bool => $get('fx_default_mode') === FxMode::Fixed->value)
                        ->visible(fn (Get $get): bool => $get('fx_conversion_enabled') === true),
                    TextInput::make('fx_markup_bps')
                        ->label(__('fx.settings.fx.markup'))
                        ->helperText(__('fx.settings.fx.markup_help', ['max' => config()->integer('axispay.limits.max_fx_markup_bps')]))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(config()->integer('axispay.limits.max_fx_markup_bps'))
                        ->required()
                        ->visible(fn (Get $get): bool => $get('fx_conversion_enabled') === true && $get('fx_default_mode') === FxMode::BanxicoFix->value),
                    TextInput::make('fx_quote_validity_minutes')
                        ->label(__('fx.settings.fx.quote_validity'))
                        ->helperText(__('fx.settings.fx.quote_validity_help', ['min' => UpdateTenantPaymentSettings::MIN_QUOTE_VALIDITY_MINUTES, 'max' => UpdateTenantPaymentSettings::MAX_QUOTE_VALIDITY_MINUTES]))
                        ->numeric()
                        ->integer()
                        ->minValue(UpdateTenantPaymentSettings::MIN_QUOTE_VALIDITY_MINUTES)
                        ->maxValue(UpdateTenantPaymentSettings::MAX_QUOTE_VALIDITY_MINUTES)
                        ->required()
                        ->visible(fn (Get $get): bool => $get('fx_conversion_enabled') === true && $get('fx_default_mode') === FxMode::BanxicoFix->value),
                ]),
            Section::make(__('fx.settings.links.heading'))
                ->description(__('fx.settings.links.description', ['days' => intdiv($platformMaxHours, 24)]))
                ->icon(Heroicon::OutlinedClock)
                ->schema([
                    TextInput::make('links_default_expiration_hours')
                        ->label(__('fx.settings.links.default'))
                        ->helperText(__('fx.settings.links.default_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(UpdateTenantPaymentSettings::MIN_EXPIRATION_HOURS)
                        ->maxValue($platformMaxHours)
                        ->suffix(__('fx.settings.links.hours'))
                        ->required(),
                    TextInput::make('links_max_expiration_hours')
                        ->label(__('fx.settings.links.max'))
                        ->helperText(__('fx.settings.links.max_help', ['hours' => $platformMaxHours, 'days' => intdiv($platformMaxHours, 24)]))
                        ->numeric()
                        ->integer()
                        ->minValue(UpdateTenantPaymentSettings::MIN_EXPIRATION_HOURS)
                        ->maxValue($platformMaxHours)
                        ->suffix(__('fx.settings.links.hours'))
                        ->required(),
                ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('fx.settings.save'))
                            ->submit('save')
                            ->visible(fn (): bool => $this->canManage()),
                    ])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $user = $this->user();
        abort_unless($user->can('manage', TenantSettings::class), 403);

        $state = $this->form->getState();
        // Fields hidden by the form (a mode that is not chosen, or everything
        // while conversion is off) are not sent: they keep their stored value.
        $current = app(TenantAccess::class)->settings($user->tenant_id);
        $rate = array_key_exists('fx_fixed_rate', $state)
            ? ExchangeRate::tryOf(is_string($state['fx_fixed_rate']) ? trim($state['fx_fixed_rate']) : null)
            : $current->fxFixedRate;

        try {
            app(UpdateTenantPaymentSettings::class)->handle($user, new TenantPaymentSettingsData(
                fxConversionEnabled: ($state['fx_conversion_enabled'] ?? false) === true,
                fxDefaultMode: self::mode($state, $current->fxDefaultMode),
                fxFixedRate: $rate,
                fxMarkupBps: array_key_exists('fx_markup_bps', $state) ? self::integer($state['fx_markup_bps']) : $current->fxMarkupBps,
                fxQuoteValidityMinutes: array_key_exists('fx_quote_validity_minutes', $state) ? self::integer($state['fx_quote_validity_minutes']) : $current->fxQuoteValidityMinutes,
                defaultExpirationHours: self::integer($state['links_default_expiration_hours'] ?? null),
                maxExpirationHours: self::integer($state['links_max_expiration_hours'] ?? null),
            ));
        } catch (InvalidPaymentSettingsException $e) {
            throw ValidationException::withMessages(array_combine(
                array_map(static fn (string $field): string => 'data.'.$field, array_keys($e->errors)),
                array_values($e->errors),
            ));
        }

        Notification::make()->success()->title(__('fx.settings.saved'))->send();
    }

    private function canManage(): bool
    {
        return $this->user()->can('manage', TenantSettings::class);
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  array<mixed>  $state
     */
    private static function mode(array $state, FxMode $stored): FxMode
    {
        if (! array_key_exists('fx_default_mode', $state)) {
            return $stored;
        }

        // An unknown value becomes `none`, which the action refuses by naming the field.
        return FxMode::tryFrom(is_string($state['fx_default_mode']) ? $state['fx_default_mode'] : '') ?? FxMode::None;
    }

    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
