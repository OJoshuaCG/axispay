<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Filament\Pages;

use App\Modules\Gateways\Actions\ConnectWithApiKey;
use App\Modules\Gateways\Actions\CreateOnboardingLink;
use App\Modules\Gateways\Actions\DisconnectGatewayConnection;
use App\Modules\Gateways\Actions\StartPlatformOnboarding;
use App\Modules\Gateways\Actions\SyncGatewayConnection;
use App\Modules\Gateways\Actions\UpdateApiKeyCredentials;
use App\Modules\Gateways\Data\ApiKeyConnectionData;
use App\Modules\Gateways\Data\ApiKeyCredentials;
use App\Modules\Gateways\Enums\ApiKeyRejection;
use App\Modules\Gateways\Enums\ConnectionError;
use App\Modules\Gateways\Enums\ConnectionMethod;
use App\Modules\Gateways\Enums\ConnectionStatus;
use App\Modules\Gateways\Exceptions\ApiKeyValidationException;
use App\Modules\Gateways\Exceptions\GatewayConnectionException;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Gateways\Stripe\Connection\ApiKeyFlow;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use App\Support\Filament\Forms\PasswordField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Locale;
use LogicException;
use SensitiveParameter;

/**
 * "Stripe connection" (plan 12.3, 17.3): state of the tenant's connection in
 * the current mode, its pending requirements, and the connect / continue /
 * update keys / disconnect actions. Only for `gateway:manage` holders;
 * every change needs the re-authentication window.
 *
 * No business logic here: each action hands its input to a Gateways action.
 * The restricted key is never shown back: after saving only `rk_…last4` is
 * displayed.
 *
 * The typed restricted key lives in Livewire state for ONE request only
 * (plan 26.2 case 19): the `rendering()` and `dehydrate()` hooks erase it
 * from every mounted action before the HTML and the snapshot are built. They
 * run on every response, whatever happened before: success, a domain
 * refusal, or a form validation error raised by Filament before the action
 * callback (unchecked notice, missing password, too long, wrong prefix).
 * The only consequence is that a refused form comes back with the key field
 * empty, to be pasted again.
 */
final class StripeConnection extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'settings/stripe';

    /**
     * Live mode only: the dangerous permissions the last attempt reported,
     * shown next to the extra confirmation checkbox (permission names only).
     *
     * @var list<string>
     */
    public array $excessivePermissions = [];

    /** Per-request memo; false = looked up, none found. */
    private GatewayConnection|false|null $connection = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can('viewAny', GatewayConnection::class);
    }

    public static function getNavigationGroup(): string
    {
        return __('gateways.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('gateways.page.title');
    }

    public function getTitle(): string
    {
        return __('gateways.page.title');
    }

    public function getSubheading(): string
    {
        return __('gateways.page.subheading.'.($this->livemode() ? 'live' : 'test'));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->flashCallout(),
            ...($this->connection() === null ? $this->connectComponents() : $this->connectionComponents()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->continueOnboardingAction(),
            $this->updateKeysAction(),
            $this->refreshStatusAction(),
            $this->disconnectAction(),
        ];
    }

    // -------------------------------------------------------------------------
    // Content
    // -------------------------------------------------------------------------

    private function flashCallout(): Callout
    {
        $flash = session('gateways.flash');

        return Callout::make(is_string($flash) ? __($flash) : '')
            ->info()
            ->icon(Heroicon::OutlinedInformationCircle)
            ->visible(is_string($flash))
            ->columnSpanFull();
    }

    /**
     * @return list<Component>
     */
    private function connectComponents(): array
    {
        $components = [];

        if (ConnectionMethod::PlatformOnboarding->isEnabled()) {
            $components[] = Section::make(__('gateways.connect.onboarding.heading'))
                ->description(__('gateways.connect.onboarding.description'))
                ->icon(Heroicon::OutlinedSparkles)
                ->schema([
                    Actions::make([$this->startOnboardingAction()]),
                ]);
        }

        if (ConnectionMethod::ApiKey->isEnabled()) {
            $components[] = Section::make(__('gateways.connect.api_key.heading'))
                ->description(__('gateways.connect.api_key.description'))
                ->icon(Heroicon::OutlinedKey)
                ->schema([
                    Callout::make(__('gateways.connect.api_key.warning_heading'))
                        ->warning()
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->description(__('gateways.connect.api_key.warning')),
                    Actions::make([$this->connectApiKeyAction(), $this->apiKeyPermissionsAction()]),
                ]);
        }

        if ($components === []) {
            $components[] = Callout::make(__('gateways.connect.none_enabled'))->warning();
        }

        return $components;
    }

    /**
     * @return list<Component>
     */
    private function connectionComponents(): array
    {
        $connection = $this->connection();
        assert($connection !== null);

        $components = [
            $this->statusCallout($connection),
            Section::make(__('gateways.details.heading'))
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema($this->detailEntries($connection)),
                ]),
        ];

        $requirements = $this->requirementsSection($connection);

        if ($requirements !== null) {
            $components[] = $requirements;
        }

        return array_values(array_filter($components));
    }

    private function statusCallout(GatewayConnection $connection): ?Callout
    {
        $key = match ($connection->status) {
            ConnectionStatus::Onboarding => 'onboarding',
            ConnectionStatus::Restricted => 'restricted',
            ConnectionStatus::InvalidCredentials => 'invalid_credentials',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $callout = Callout::make(__("gateways.callout.{$key}.heading"))
            ->description(__("gateways.callout.{$key}.body"))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->columnSpanFull();

        return $connection->status === ConnectionStatus::InvalidCredentials ? $callout->danger() : $callout->warning();
    }

    /**
     * @return list<Component>
     */
    private function detailEntries(GatewayConnection $connection): array
    {
        $entries = [
            TextEntry::make('status')
                ->label(__('gateways.fields.status'))
                ->state($connection->status->label())
                ->badge()
                ->color($connection->status->color()),
            TextEntry::make('method')
                ->label(__('gateways.fields.method'))
                ->state($connection->connection_method->label()),
            TextEntry::make('account')
                ->label(__('gateways.fields.account'))
                ->state($connection->provider_account_id)
                ->placeholder('—')
                ->fontFamily(FontFamily::Mono)
                ->copyable(),
            TextEntry::make('country')
                ->label(__('gateways.fields.country'))
                ->state(self::countryName($connection->country))
                ->placeholder('—'),
            TextEntry::make('currency')
                ->label(__('gateways.fields.default_currency'))
                ->state($connection->default_currency !== null ? strtoupper($connection->default_currency) : null)
                ->placeholder('—'),
            IconEntry::make('charges_enabled')
                ->label(__('gateways.fields.charges_enabled'))
                ->state($connection->charges_enabled)
                ->boolean()
                ->tooltip($connection->charges_enabled ? __('gateways.fields.yes') : __('gateways.fields.no')),
            IconEntry::make('payouts_enabled')
                ->label(__('gateways.fields.payouts_enabled'))
                ->state($connection->payouts_enabled)
                ->boolean()
                ->tooltip($connection->payouts_enabled ? __('gateways.fields.yes') : __('gateways.fields.no')),
            TextEntry::make('last_synced_at')
                ->label(__('gateways.fields.last_synced_at'))
                ->state($connection->last_synced_at)
                ->dateTime()
                ->placeholder('—'),
        ];

        if ($connection->isApiKey()) {
            $entries[] = TextEntry::make('restricted_key')
                ->label(__('gateways.fields.restricted_key'))
                ->state($connection->maskedSecret())
                ->fontFamily(FontFamily::Mono)
                ->placeholder('—');
            $entries[] = TextEntry::make('last_health_check_at')
                ->label(__('gateways.fields.last_health_check_at'))
                ->state($connection->last_health_check_at)
                ->dateTime()
                ->placeholder('—');

            $excessive = $connection->validated_permissions['excessive'] ?? [];

            if (is_array($excessive) && $excessive !== []) {
                $entries[] = Callout::make(__('gateways.details.excessive_heading'))
                    ->warning()
                    ->description(__('gateways.details.excessive_body', ['permissions' => implode(', ', array_filter($excessive, is_string(...)))]))
                    ->columnSpanFull();
            }
        }

        return $entries;
    }

    private function requirementsSection(GatewayConnection $connection): ?Section
    {
        $requirements = $connection->requirements ?? [];
        $due = [];

        foreach (['past_due', 'currently_due', 'eventually_due'] as $group) {
            $values = $requirements[$group] ?? [];

            foreach (is_array($values) ? $values : [] as $value) {
                if (is_string($value)) {
                    $due[$value] = true;
                }
            }
        }

        $reason = $requirements['disabled_reason'] ?? null;

        if ($due === [] && ! is_string($reason)) {
            return null;
        }

        $schema = [];

        if (is_string($reason)) {
            $schema[] = Text::make(__('gateways.requirements.reason.'.self::reasonGroup($reason)));
        }

        $deadline = $requirements['current_deadline'] ?? null;

        if (is_int($deadline)) {
            $schema[] = Text::make(__('gateways.requirements.deadline', ['date' => now()->setTimestamp($deadline)->isoFormat('LL')]));
        }

        if ($due !== []) {
            $schema[] = TextEntry::make('requirements_due')
                ->label(__('gateways.requirements.intro', ['count' => count($due)]))
                ->state(array_keys($due))
                ->listWithLineBreaks()
                ->bulleted()
                ->fontFamily(FontFamily::Mono)
                // Requirement IDs are long dotted tokens: break them anywhere at 320px.
                ->extraAttributes(['class' => 'break-all']);
        }

        return Section::make(__('gateways.requirements.heading'))
            ->description($connection->connection_method === ConnectionMethod::PlatformOnboarding ? self::text('gateways.requirements.description') : null)
            ->schema($schema);
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    public function startOnboardingAction(): Action
    {
        $countries = ApiKeyFlow::allowedCountries();

        return Action::make('startOnboarding')
            ->label(__('gateways.actions.start_onboarding'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->authorize('manage', GatewayConnection::class)
            ->modalHeading(__('gateways.actions.start_onboarding'))
            ->modalDescription(__('gateways.actions.start_onboarding_help'))
            ->modalSubmitActionLabel(__('gateways.actions.go_to_stripe'))
            ->schema([
                Select::make('country')
                    ->label(__('gateways.fields.country'))
                    ->options(array_combine($countries, array_map(static fn (string $country): string => self::countryName($country) ?? $country, $countries)))
                    ->default($countries[0] ?? null)
                    ->helperText(__('gateways.actions.country_help'))
                    ->visible(count($countries) > 1)
                    ->required(),
                Reauthentication::field(),
            ])
            ->action(function (array $data, Action $action) use ($countries): void {
                $country = is_string($data['country'] ?? null) ? $data['country'] : ($countries[0] ?? '');

                $url = $this->run($action, $data, fn (User $user): string => app(StartPlatformOnboarding::class)->handle($user, $country));

                $this->redirect($url);
            });
    }

    public function continueOnboardingAction(): Action
    {
        return Action::make('continueOnboarding')
            ->label(__('gateways.actions.continue_onboarding'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->visible(fn (): bool => ($connection = $this->connection()) !== null
                && $connection->connection_method === ConnectionMethod::PlatformOnboarding
                && in_array($connection->status, [ConnectionStatus::Onboarding, ConnectionStatus::Restricted], true))
            ->authorize('manage', GatewayConnection::class)
            ->modalDescription(__('gateways.actions.continue_onboarding_help'))
            ->modalSubmitActionLabel(__('gateways.actions.go_to_stripe'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data, Action $action): void {
                $url = $this->run($action, $data, fn (User $user): string => app(CreateOnboardingLink::class)->handle($user, $this->requireConnection()));

                $this->redirect($url);
            });
    }

    public function refreshStatusAction(): Action
    {
        return Action::make('refreshStatus')
            ->label(__('gateways.actions.refresh'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => $this->connection() !== null)
            ->authorize('manage', GatewayConnection::class)
            ->action(function (Action $action): void {
                try {
                    app(SyncGatewayConnection::class)->handle($this->requireConnection());
                } catch (GatewayException) {
                    Notification::make()->danger()->title(__('gateways.errors.gateway_unavailable'))->send();
                    $action->halt();
                }

                $this->refreshContent();
                Notification::make()->success()->title(__('gateways.notifications.refreshed'))->send();
            });
    }

    public function connectApiKeyAction(): Action
    {
        return $this->apiKeyAction('connectApiKey', __('gateways.actions.connect_api_key'))
            // One primary action per view: the recommended method (ADR-0044).
            ->color('gray')
            ->action(function (array $data, Action $action): void {
                $this->run($action, $data, fn (User $user): GatewayConnection => app(ConnectWithApiKey::class)->handle($user, self::apiKeyData($data)));

                $this->afterApiKeySaved('gateways.notifications.api_key_connected');
            });
    }

    public function updateKeysAction(): Action
    {
        return $this->apiKeyAction('updateKeys', __('gateways.actions.update_keys'))
            ->color('gray')
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (): bool => ($connection = $this->connection()) !== null && $connection->isApiKey())
            ->action(function (array $data, Action $action): void {
                $this->run($action, $data, fn (User $user): GatewayConnection => app(UpdateApiKeyCredentials::class)->handle($user, $this->requireConnection(), self::apiKeyData($data)));

                $this->afterApiKeySaved('gateways.notifications.api_key_updated');
            });
    }

    /**
     * "View required permissions": read-only help for the api_key method
     * (the restricted key's permissions and how to create it). No submit and
     * no server-side effect.
     */
    public function apiKeyPermissionsAction(): Action
    {
        return Action::make('apiKeyPermissions')
            ->label(__('gateways.permissions_help.action'))
            ->icon(Heroicon::OutlinedInformationCircle)
            ->color('gray')
            ->outlined()
            ->visible(static fn (): bool => ConnectionMethod::ApiKey->isEnabled())
            ->modalHeading(__('gateways.permissions_help.heading'))
            ->modalIcon(Heroicon::OutlinedInformationCircle)
            ->modalWidth('3xl')
            ->modalContent(fn (): View => $this->permissionsHelpView())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('gateways.permissions_help.close'));
    }

    private function permissionsHelpView(): View
    {
        return view('filament.gateways.stripe-key-permissions', [
            'mode' => self::text('gateways.mode.'.($this->livemode() ? 'live' : 'test')),
        ]);
    }

    private function apiKeyAction(string $name, string $label): Action
    {
        $mode = $this->livemode() ? 'live' : 'test';

        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedKey)
            ->authorize('manage', GatewayConnection::class)
            ->modalHeading($label)
            ->modalDescription(__('gateways.api_key.help', ['mode' => __('gateways.mode.'.$mode)]))
            ->modalWidth('2xl')
            ->schema([
                Callout::make(__('gateways.api_key.risk.heading'))
                    ->warning()
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->description(__('gateways.api_key.risk.body')),
                // The permissions help, collapsed, inside the form. Not a
                // nested modal: opening one is a server round-trip, and this
                // page erases the typed key on every response (case 19), so
                // the merchant would lose what they pasted. Expanding a
                // section happens in the browser only.
                Section::make(__('gateways.permissions_help.form_heading'))
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->collapsible()
                    ->collapsed()
                    ->compact()
                    ->schema([
                        SchemaView::make('filament.gateways.stripe-key-permissions')
                            ->viewData(['mode' => self::text('gateways.mode.'.$mode)]),
                    ]),
                PasswordField::make('restricted_key')
                    ->forSecret(__('gateways.api_key.show_key'), __('gateways.api_key.hide_key'))
                    ->label(__('gateways.api_key.restricted_key'))
                    ->placeholder("rk_{$mode}_…")
                    ->helperText(__('gateways.api_key.restricted_key_help'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('publishable_key')
                    ->label(__('gateways.api_key.publishable_key'))
                    ->placeholder("pk_{$mode}_…")
                    ->helperText(__('gateways.api_key.publishable_key_help'))
                    ->autocomplete('off')
                    ->extraInputAttributes(['spellcheck' => 'false', 'autocapitalize' => 'none'])
                    ->required()
                    ->maxLength(255),
                Checkbox::make('risk_acknowledged')
                    ->label(__('gateways.api_key.risk.accept'))
                    ->accepted()
                    ->required(),
                Checkbox::make('accept_excessive_permissions')
                    ->label(fn (): string => __('gateways.api_key.accept_excessive', ['permissions' => implode(', ', $this->excessivePermissions)]))
                    ->visible(fn (): bool => $this->excessivePermissions !== []),
                Reauthentication::field(),
            ]);
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label(__('gateways.actions.disconnect'))
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('danger')
            // Destructive but secondary: never louder than the primary action.
            ->outlined()
            ->visible(fn (): bool => $this->connection() !== null)
            ->authorize('manage', GatewayConnection::class)
            ->requiresConfirmation()
            ->modalHeading(__('gateways.actions.disconnect_heading'))
            ->modalDescription(fn (): string => __('gateways.actions.disconnect_help.'.($this->connection()?->connection_method->value ?? 'platform_onboarding')))
            ->modalSubmitActionLabel(__('gateways.actions.disconnect'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data, Action $action): void {
                $this->run($action, $data, fn (User $user): GatewayConnection => app(DisconnectGatewayConnection::class)->handle($user, $this->requireConnection()));

                $this->refreshContent();
                Notification::make()->success()->title(__('gateways.notifications.disconnected'))->send();
            });
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Re-authentication, then the domain action; domain refusals become form
     * errors or notifications. The restricted key is cleared from the form
     * whenever the action does not complete.
     *
     * @template TResult
     *
     * @param  array<mixed>  $data
     * @param  callable(User): TResult  $callback
     * @return TResult
     */
    private function run(Action $action, #[SensitiveParameter] array $data, callable $callback): mixed
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            Reauthentication::confirm($data);

            return $callback($user);
        } catch (ApiKeyValidationException $e) {
            $this->excessivePermissions = $e->rejection === ApiKeyRejection::ExcessivePermissionsNotConfirmed ? $e->details : [];

            throw ValidationException::withMessages(['mountedActions.0.data.'.$e->rejection->field() => $e->userMessage()]);
        } catch (GatewayConnectionException $e) {
            Notification::make()->danger()->title($e->userMessage())->send();
            $action->halt();
        } catch (ReauthenticationRequiredException) {
            Notification::make()->danger()->title(__('gateways.errors.reauthentication_required'))->send();
            $action->halt();
        } finally {
            $this->forgetSecret();
        }

        throw new LogicException('Unreachable: the action halted.');
    }

    private function afterApiKeySaved(string $message): void
    {
        $this->excessivePermissions = [];
        $this->refreshContent();
        Notification::make()->success()->title(__($message))->send();
    }

    /** The connection changed: rebuild the page content on this render. */
    private function refreshContent(): void
    {
        $this->connection = null;
        unset($this->cachedSchemas['content']);
    }

    /** Livewire hook: before the HTML of every response is rendered. */
    public function rendering(): void
    {
        $this->forgetSecret();
    }

    /** Livewire hook: before the snapshot of every response is built. */
    public function dehydrate(): void
    {
        $this->forgetSecret();
    }

    /** Plan 26.2 case 19: the typed key never travels back to the browser. */
    private function forgetSecret(): void
    {
        $mounted = $this->mountedActions ?? [];

        foreach ($mounted as $index => $action) {
            $data = $action['data'] ?? null;

            if (is_array($data) && array_key_exists('restricted_key', $data)) {
                $data['restricted_key'] = null;
                $action['data'] = $data;
                $mounted[$index] = $action;
            }
        }

        $this->mountedActions = $mounted;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function apiKeyData(#[SensitiveParameter] array $data): ApiKeyConnectionData
    {
        return new ApiKeyConnectionData(
            credentials: ApiKeyCredentials::from(
                is_string($data['restricted_key'] ?? null) ? $data['restricted_key'] : '',
                is_string($data['publishable_key'] ?? null) ? $data['publishable_key'] : '',
            ),
            riskAcknowledged: ($data['risk_acknowledged'] ?? false) === true,
            acceptExcessivePermissions: ($data['accept_excessive_permissions'] ?? false) === true,
        );
    }

    private function connection(): ?GatewayConnection
    {
        if ($this->connection === null) {
            $this->connection = GatewayConnection::query()->current()->first() ?? false;
        }

        return $this->connection === false ? null : $this->connection;
    }

    private function requireConnection(): GatewayConnection
    {
        return $this->connection() ?? throw new GatewayConnectionException(ConnectionError::NotOnboarding);
    }

    private function livemode(): bool
    {
        return app(TenantContext::class)->livemodeOrNull() ?? false;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    private static function countryName(?string $country): ?string
    {
        if ($country === null) {
            return null;
        }

        $name = Locale::getDisplayRegion('-'.$country, app()->getLocale());

        return $name !== '' && $name !== $country ? "{$name} ({$country})" : $country;
    }

    /** Stripe's `disabled_reason` values grouped into a few plain messages. */
    private static function reasonGroup(string $reason): string
    {
        return match (true) {
            str_starts_with($reason, 'requirements.'), str_starts_with($reason, 'action_required.') => 'information_needed',
            str_starts_with($reason, 'rejected.') => 'rejected',
            $reason === 'under_review' => 'under_review',
            default => 'paused',
        };
    }
}
