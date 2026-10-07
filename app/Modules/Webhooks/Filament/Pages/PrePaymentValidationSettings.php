<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Pages;

use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Modules\Identity\Models\User;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource;
use App\Modules\Webhooks\Actions\ConfigureValidationEndpoint;
use App\Modules\Webhooks\Actions\RemoveValidationEndpoint;
use App\Modules\Webhooks\Actions\RotateValidationEndpointSecret;
use App\Modules\Webhooks\Actions\TestValidationEndpoint;
use App\Modules\Webhooks\Data\IssuedValidationEndpoint;
use App\Modules\Webhooks\Data\ValidationEndpointData;
use App\Modules\Webhooks\Data\ValidationTestResult;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Filament\Concerns\ShowsIssuedSecret;
use App\Modules\Webhooks\Filament\Concerns\ShowsTestResult;
use App\Modules\Webhooks\Filament\Contracts\PresentsTestResults;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Modules\Webhooks\Filament\Support\IntegrationHelp;
use App\Modules\Webhooks\Filament\Support\TestResultPresenter;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Support\Filament\PanelDefaults;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use SensitiveParameter;

/**
 * "Pre-payment validation" in the tenant panel (plan 15.8.1, 15.8.5,
 * ADR-0058), for the current mode: the URL, the failure policy explained in
 * plain words, whether new links use it by default, the secret (shown once,
 * rotated with 24 hours of overlap), removing it, "Test validation", the
 * alert after repeated failures and the recent calls. `webhooks:manage`;
 * every change goes through a Webhooks action, which re-checks the
 * permission, the re-authentication window and the SSRF protection.
 * "How it works" (IntegrationHelp) explains the call to the developer.
 *
 * Its own view renders the action modals: see the comment in
 * `filament.webhooks.pages.pre-payment-validation-settings`.
 */
final class PrePaymentValidationSettings extends Page implements HasTable, PresentsTestResults
{
    use InteractsWithTable;
    use ShowsIssuedSecret;
    use ShowsTestResult;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 82;

    protected static ?string $slug = 'settings/pre-payment-validation';

    protected string $view = 'filament.webhooks.pages.pre-payment-validation-settings';

    /** Per-request memo; false = looked up, none configured. */
    private ValidationEndpoint|false|null $endpoint = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->can('viewAny', ValidationEndpoint::class);
    }

    public static function getNavigationGroup(): string
    {
        return __('webhooks.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('webhooks.validation.page.title');
    }

    public function getTitle(): string
    {
        return __('webhooks.validation.page.title');
    }

    public function getSubheading(): string
    {
        return __('webhooks.validation.page.subheading.'.TenantPanel::modeKey());
    }

    public function content(Schema $schema): Schema
    {
        $endpoint = $this->endpoint();

        return $schema->components([
            Callout::make(__('webhooks.validation.alert.heading'))
                ->description(fn (): string => $endpoint !== null ? __('webhooks.validation.alert.description', [
                    'failures' => $endpoint->consecutive_failures,
                    'policy' => $endpoint->failure_policy->label(),
                    'date' => $endpoint->last_failure_at !== null ? self::panelDate($endpoint->last_failure_at) : '—',
                ]) : '')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->danger()
                ->visible($endpoint !== null && $endpoint->isFailing())
                ->columnSpanFull(),
            $endpoint === null ? $this->notConfiguredSection() : $this->settingsSection($endpoint),
            Section::make(__('webhooks.validation.calls.title'))
                ->description(__('webhooks.validation.calls.description'))
                ->columnSpanFull()
                ->visible($endpoint !== null || ValidationCall::query()->exists())
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ValidationCall::query()->with('link'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('webhooks.validation.calls.time'))
                    ->dateTime()
                    ->description(static function (ValidationCall $record): ?string {
                        if (! $record->is_test) {
                            return null;
                        }

                        return __('webhooks.validation.calls.test');
                    }),
                TextColumn::make('link.description')
                    ->label(__('webhooks.validation.calls.link'))
                    ->state(static fn (ValidationCall $record): ?string => $record->link?->description)
                    ->description(static function (ValidationCall $record): ?string {
                        if ($record->attempt_number === null) {
                            return null;
                        }

                        return __('webhooks.validation.calls.attempt_number', ['number' => $record->attempt_number]);
                    })
                    ->url(static fn (ValidationCall $record): ?string => $record->link !== null && PaymentLinkResource::canView($record->link) ? PaymentLinkResource::getUrl('view', ['record' => $record->link]) : null)
                    ->limit(50)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('outcome')
                    ->label(__('webhooks.validation.calls.outcome'))
                    ->badge()
                    ->formatStateUsing(static fn (ValidationCall $record): string => $record->outcome?->label() ?? '—')
                    ->color(static fn (ValidationCall $record): string => $record->outcome?->color() ?? 'gray')
                    ->description(static fn (ValidationCall $record): ?string => self::callDetail($record))
                    ->placeholder('—'),
                TextColumn::make('response_status')
                    ->label(__('webhooks.validation.calls.http_status'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('duration_ms')
                    ->label(__('webhooks.validation.calls.latency'))
                    ->formatStateUsing(static fn (ValidationCall $record): string => TestResultPresenter::latency($record->duration_ms))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->alignEnd()
                    ->visibleFrom('md'),
            ])
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading(__('webhooks.validation.calls.empty_heading'))
            ->emptyStateDescription(__('webhooks.validation.calls.empty_description'))
            ->emptyStateActions([IntegrationHelp::hintAction('validationHelpFromEmptyState', IntegrationHelp::VALIDATION_ACTION)]);
    }

    protected function getHeaderActions(): array
    {
        return [
            IntegrationHelp::validationAction(),
            $this->configureAction(),
            $this->testAction(),
            // Less frequent, sensitive actions, as on the webhook endpoint page.
            ActionGroup::make([
                $this->rotateAction(),
                $this->removeAction(),
            ])
                ->label(__('webhooks.actions.more'))
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray')
                ->button(),
        ];
    }

    private function notConfiguredSection(): Section
    {
        return Section::make(__('webhooks.validation.page.not_configured'))
            ->description(__('webhooks.validation.page.not_configured_help'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->columnSpanFull()
            ->schema([
                TextEntry::make('how_it_works')
                    ->hiddenLabel()
                    ->state(__('webhooks.validation.page.how_it_works', ['seconds' => config()->integer('axispay.pre_payment_validation.timeout_seconds')])),
                Actions::make([IntegrationHelp::hintAction('validationHelpFromSection', IntegrationHelp::VALIDATION_ACTION)]),
            ]);
    }

    private function settingsSection(ValidationEndpoint $endpoint): Section
    {
        return Section::make(__('webhooks.validation.page.settings'))
            ->columnSpanFull()
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextEntry::make('url')
                        ->label(__('webhooks.validation.fields.url'))
                        ->state($endpoint->url)
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(['class' => 'break-all'])
                        ->columnSpanFull(),
                    TextEntry::make('failure_policy')
                        ->label(__('webhooks.validation.fields.failure_policy'))
                        ->state($endpoint->failure_policy->label())
                        ->belowContent($endpoint->failure_policy->explanation())
                        ->columnSpanFull(),
                    TextEntry::make('enabled_by_default')
                        ->label(__('webhooks.validation.fields.enabled_by_default'))
                        ->state($endpoint->enabled_by_default ? __('webhooks.validation.page.default_on') : __('webhooks.validation.page.default_off')),
                    TextEntry::make('last_success_at')
                        ->label(__('webhooks.validation.fields.last_success_at'))
                        ->state($endpoint->last_success_at !== null ? self::panelDate($endpoint->last_success_at) : null)
                        ->placeholder(__('webhooks.validation.page.never')),
                    TextEntry::make('previous_secret')
                        ->label(__('webhooks.fields.previous_secret'))
                        ->state(WebhookEndpointResource::previousSecretLine($endpoint->previous_secret_expires_at))
                        ->visible($endpoint->previous_secret_expires_at?->isFuture() === true)
                        ->columnSpanFull(),
                ]),
            ]);
    }

    private function configureAction(): Action
    {
        return Action::make('configure')
            ->label(fn (): string => $this->endpoint() === null ? __('webhooks.validation.actions.configure') : __('webhooks.validation.actions.edit'))
            ->icon(fn (): Heroicon => $this->endpoint() === null ? Heroicon::OutlinedPlus : Heroicon::OutlinedPencilSquare)
            ->color(fn (): string => $this->endpoint() === null ? 'primary' : 'gray')
            ->authorize(fn (): bool => ($endpoint = $this->endpoint()) === null
                ? TenantPanel::user()->can('create', ValidationEndpoint::class)
                : TenantPanel::user()->can('update', $endpoint))
            ->modalHeading(fn (): string => $this->endpoint() === null ? __('webhooks.validation.actions.configure') : __('webhooks.validation.actions.edit'))
            ->modalDescription(fn (): string => __('webhooks.validation.actions.configure_help.'.TenantPanel::modeKey()))
            ->modalSubmitActionLabel(__('webhooks.validation.actions.save'))
            ->modalWidth('3xl')
            ->fillForm(fn (): array => [
                'url' => $this->endpoint()?->url,
                'failure_policy' => ($this->endpoint()->failure_policy ?? ValidationFailurePolicy::FailClosed)->value,
                'enabled_by_default' => $this->endpoint()->enabled_by_default ?? false,
            ])
            ->schema([
                TextInput::make('url')
                    ->label(__('webhooks.validation.fields.url'))
                    ->helperText(fn (): string => __('webhooks.fields.url_help.'.TenantPanel::modeKey()))
                    ->placeholder('https://')
                    ->url()
                    ->required()
                    ->validationMessages(['required' => __('webhooks.form.url_required')])
                    ->maxLength(2048)
                    ->extraInputAttributes(['class' => 'font-numeric']),
                Radio::make('failure_policy')
                    ->label(__('webhooks.validation.fields.failure_policy'))
                    ->helperText(__('webhooks.validation.fields.failure_policy_help', ['seconds' => config()->integer('axispay.pre_payment_validation.timeout_seconds')]))
                    ->options(self::policyOptions())
                    ->descriptions(self::policyDescriptions())
                    ->in(array_map(static fn (ValidationFailurePolicy $p): string => $p->value, ValidationFailurePolicy::cases()))
                    ->required(),
                Toggle::make('enabled_by_default')
                    ->label(__('webhooks.validation.fields.enabled_by_default'))
                    ->helperText(__('webhooks.validation.fields.enabled_by_default_help')),
                Reauthentication::field(),
            ])
            ->action(function (array $data, Action $action): void {
                $issued = WebhookEndpointResource::run(
                    $action,
                    $data,
                    static fn (): IssuedValidationEndpoint => app(ConfigureValidationEndpoint::class)->handle(TenantPanel::user(), self::endpointData($data)),
                    fields: ['url'],
                );

                $this->refreshContent();

                if ($issued->secret !== null) {
                    $this->showIssuedSecret($issued->secret, 'created', __('webhooks.validation.notifications.configured'));

                    return;
                }

                Notification::make()->success()->title(__('webhooks.validation.notifications.updated'))->send();
            });
    }

    private function testAction(): Action
    {
        return Action::make('testValidation')
            ->label(__('webhooks.validation.actions.test'))
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->visible(fn (): bool => $this->endpoint() !== null)
            ->authorize(fn (): bool => ($endpoint = $this->endpoint()) !== null && TenantPanel::user()->can('test', $endpoint))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedBeaker)
            ->modalHeading(__('webhooks.validation.actions.test_heading'))
            ->modalDescription(fn (): string => __('webhooks.validation.actions.test_help', ['host' => $this->endpoint()?->host() ?? '']))
            ->modalSubmitActionLabel(__('webhooks.validation.actions.test_submit'))
            ->action(function (Action $action): void {
                $endpoint = $this->endpoint();

                if ($endpoint === null) {
                    return;
                }

                $result = WebhookEndpointResource::run(
                    $action,
                    [],
                    static fn (): ValidationTestResult => app(TestValidationEndpoint::class)->handle(TenantPanel::user(), $endpoint),
                    reauthenticate: false,
                );

                $this->refreshContent();
                $this->showTestResult(TestResultPresenter::validation($result), __('webhooks.test_result.validation_heading', ['host' => $endpoint->host()]));
            });
    }

    private function rotateAction(): Action
    {
        return Action::make('rotateSecret')
            ->label(__('webhooks.actions.rotate'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => $this->endpoint() !== null)
            ->authorize(fn (): bool => ($endpoint = $this->endpoint()) !== null && TenantPanel::user()->can('update', $endpoint))
            ->requiresConfirmation()
            ->modalHeading(__('webhooks.validation.actions.rotate_heading'))
            ->modalDescription(__('webhooks.validation.actions.rotate_help'))
            ->modalSubmitActionLabel(__('webhooks.actions.rotate_submit'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data, Action $action): void {
                $endpoint = $this->endpoint();

                if ($endpoint === null) {
                    return;
                }

                $issued = WebhookEndpointResource::run(
                    $action,
                    $data,
                    static fn (): IssuedValidationEndpoint => app(RotateValidationEndpointSecret::class)->handle(TenantPanel::user(), $endpoint),
                );

                $this->refreshContent();
                $this->showIssuedSecret((string) $issued->secret, 'rotated', __('webhooks.notifications.rotated'));
            });
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('webhooks.validation.actions.remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->outlined()
            ->visible(fn (): bool => $this->endpoint() !== null)
            ->authorize(fn (): bool => ($endpoint = $this->endpoint()) !== null && TenantPanel::user()->can('delete', $endpoint))
            ->requiresConfirmation()
            ->modalHeading(__('webhooks.validation.actions.remove_heading'))
            ->modalDescription(__('webhooks.validation.actions.remove_help'))
            ->modalSubmitActionLabel(__('webhooks.validation.actions.remove_submit'))
            ->schema([Reauthentication::field()])
            ->action(function (array $data, Action $action): void {
                $endpoint = $this->endpoint();

                if ($endpoint === null) {
                    return;
                }

                WebhookEndpointResource::run(
                    $action,
                    $data,
                    static fn () => app(RemoveValidationEndpoint::class)->handle(TenantPanel::user(), $endpoint),
                );

                $this->refreshContent();
                Notification::make()->success()->title(__('webhooks.validation.notifications.removed'))->send();
            });
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function endpointData(#[SensitiveParameter] array $data): ValidationEndpointData
    {
        return new ValidationEndpointData(
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            enabledByDefault: ($data['enabled_by_default'] ?? false) === true,
            failurePolicy: ValidationFailurePolicy::tryFrom(is_string($data['failure_policy'] ?? null) ? $data['failure_policy'] : '') ?? ValidationFailurePolicy::FailClosed,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function policyOptions(): array
    {
        $options = [];

        foreach (ValidationFailurePolicy::cases() as $policy) {
            $options[$policy->value] = $policy->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function policyDescriptions(): array
    {
        $descriptions = [];

        foreach (ValidationFailurePolicy::cases() as $policy) {
            $descriptions[$policy->value] = $policy->explanation();
        }

        return $descriptions;
    }

    /** The reason of a rejection, or why a call failed and the policy applied. */
    public static function callDetail(ValidationCall $call): ?string
    {
        if ($call->outcome === ValidationCallOutcome::Rejected && $call->reason_code !== null) {
            return __('webhooks.validation.calls.reason', ['reason' => $call->reason_code]);
        }

        if ($call->outcome === ValidationCallOutcome::Failed && $call->failure_kind !== null) {
            return $call->policy_applied !== null
                ? __('webhooks.validation.calls.failure_with_policy', ['kind' => $call->failure_kind->label(), 'policy' => $call->policy_applied->label()])
                : $call->failure_kind->label();
        }

        return null;
    }

    private function endpoint(): ?ValidationEndpoint
    {
        if ($this->endpoint === null) {
            $this->endpoint = ValidationEndpoint::query()->first() ?? false;
        }

        return $this->endpoint === false ? null : $this->endpoint;
    }

    /** The endpoint changed: rebuild the page content on this render. */
    private function refreshContent(): void
    {
        $this->endpoint = null;
        unset($this->cachedSchemas['content']);
    }

    private static function panelDate(CarbonImmutable $time): string
    {
        return $time->setTimezone(FilamentTimezone::get())->translatedFormat(PanelDefaults::DATE_TIME_FORMAT);
    }
}
