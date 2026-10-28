<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Resources\WebhookEndpoints;

use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Modules\Webhooks\Actions\SendTestWebhook;
use App\Modules\Webhooks\Actions\TestValidationEndpoint;
use App\Modules\Webhooks\Data\ValidationTestResult;
use App\Modules\Webhooks\Data\WebhookEndpointData;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Exceptions\UnsafeDestinationException;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;
use App\Modules\Webhooks\Filament\Contracts\PresentsTestResults;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ListWebhookEndpoints;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\Pages\ViewWebhookEndpoint;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\RelationManagers\DeliveriesRelationManager;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\RelationManagers\ValidationCallsRelationManager;
use App\Modules\Webhooks\Filament\Support\IntegrationHelp;
use App\Modules\Webhooks\Filament\Support\TestResultPresenter;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\ValidationCallMetrics;
use App\Support\Filament\Concerns\SentenceCaseLabels;
use App\Support\Filament\DomainErrors;
use App\Support\Filament\PanelDefaults;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Tenant panel: webhook endpoints of the current mode (plan 15.1;
 * `webhooks:manage`). List with the health of each endpoint, create
 * (ListWebhookEndpoints: the secret is shown once), and on the detail edit,
 * send a test event, rotate or reveal the secret, disable, enable, delete
 * and the delivery log with manual resend. Presentation only: every change
 * goes through a Webhooks action, which re-checks the permission, the
 * re-authentication window and the SSRF protection. Endpoints of the other
 * mode never show (BelongsToMode); another tenant's endpoint is a 404.
 */
final class WebhookEndpointResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = WebhookEndpoint::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?int $navigationSort = 81;

    protected static ?string $slug = 'settings/webhooks';

    /** Form fields named like the WebhookEndpointData properties. */
    public const array FIELDS = ['url', 'description', 'events', 'validation_failure_policy'];

    public static function getModelLabel(): string
    {
        return __('webhooks.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('webhooks.plural');
    }

    public static function getNavigationGroup(): string
    {
        return __('webhooks.navigation.group');
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof WebhookEndpoint ? $record->host() : self::getModelLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('url')
                    ->label(__('webhooks.fields.url'))
                    ->fontFamily(FontFamily::Mono)
                    ->description(static fn (WebhookEndpoint $record): ?string => $record->description)
                    ->extraAttributes(['class' => 'break-all'])
                    ->wrap(),
                TextColumn::make('enabled_events')
                    ->label(__('webhooks.fields.events'))
                    ->badge()
                    ->color('gray')
                    ->state(static fn (WebhookEndpoint $record): array => self::eventLabels($record))
                    ->wrap()
                    ->visibleFrom('lg'),
                TextColumn::make('status')
                    ->label(__('webhooks.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (WebhookEndpoint $record): string => $record->status->label())
                    ->color(static fn (WebhookEndpoint $record): string => $record->status->color())
                    ->icon(static fn (WebhookEndpoint $record): Heroicon => $record->status->icon()),
                TextColumn::make('failing_since')
                    ->label(__('webhooks.fields.health'))
                    ->badge()
                    ->state(static fn (WebhookEndpoint $record): ?string => self::failingLine($record))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->placeholder(static fn (WebhookEndpoint $record): string => self::healthPlaceholder($record))
                    ->visibleFrom('md'),
                TextColumn::make('created_at')->label(__('webhooks.fields.created_at'))->dateTime()->sortable()->visibleFrom('xl'),
            ])
            ->defaultSort('id', 'desc')
            ->paginated(false)
            ->emptyStateIcon(Heroicon::OutlinedSignal)
            ->emptyStateHeading(__('webhooks.empty.heading'))
            ->emptyStateDescription(__('webhooks.empty.description'))
            ->emptyStateActions([
                Action::make('createFromEmptyState')
                    ->label(__('webhooks.actions.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize('create', WebhookEndpoint::class)
                    ->outlined()
                    ->alpineClickHandler("\$wire.mountAction('create')"),
                IntegrationHelp::hintAction('webhooksHelpFromEmptyState', IntegrationHelp::WEBHOOKS_ACTION),
            ])
            // An icon in the row (the label stays as its accessible name and tooltip).
            ->recordActions([self::sendTestAction()->iconButton()->tooltip(__('webhooks.actions.send_test'))]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        TextEntry::make('url')
                            ->label(__('webhooks.fields.url'))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage(__('webhooks.copied'))
                            ->extraAttributes(['class' => 'break-all'])
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->label(__('webhooks.fields.description'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('status')
                            ->label(__('webhooks.fields.status'))
                            ->badge()
                            ->formatStateUsing(static fn (WebhookEndpoint $record): string => $record->status->label())
                            ->color(static fn (WebhookEndpoint $record): string => $record->status->color())
                            ->icon(static fn (WebhookEndpoint $record): Heroicon => $record->status->icon())
                            ->belowContent(static function (WebhookEndpoint $record): ?string {
                                if ($record->status->isEnabled()) {
                                    return null;
                                }

                                return __('webhooks.status_help.'.$record->status->value);
                            }),
                        TextEntry::make('failing_since')
                            ->label(__('webhooks.fields.health'))
                            ->state(static fn (WebhookEndpoint $record): ?string => self::failingLine($record))
                            ->color('danger')
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->belowContent(static function (WebhookEndpoint $record): ?string {
                                if ($record->failing_since === null) {
                                    return null;
                                }

                                return __('webhooks.failing_help');
                            })
                            ->placeholder(static fn (WebhookEndpoint $record): string => self::healthPlaceholder($record)),
                        TextEntry::make('enabled_events')
                            ->label(__('webhooks.fields.events'))
                            ->badge()
                            ->color('gray')
                            ->state(static fn (WebhookEndpoint $record): array => self::eventLabels($record))
                            ->columnSpanFull(),
                        TextEntry::make('validation')
                            ->label(__('webhooks.fields.validation'))
                            ->state(static fn (WebhookEndpoint $record): string => $record->validates() ? __('webhooks.fields.validation_on') : __('webhooks.fields.validation_off'))
                            ->badge()
                            ->color(static fn (WebhookEndpoint $record): string => $record->validates() ? 'success' : 'gray')
                            ->belowContent(static fn (WebhookEndpoint $record): ?string => $record->validates() ? $record->validation_failure_policy->explanation() : null)
                            ->columnSpanFull(),
                        TextEntry::make('validation_enabled_by_default')
                            ->label(__('webhooks.validation.fields.enabled_by_default'))
                            ->state(static fn (WebhookEndpoint $record): string => $record->validation_enabled_by_default ? __('webhooks.validation.page.default_on') : __('webhooks.validation.page.default_off'))
                            ->visible(static fn (WebhookEndpoint $record): bool => $record->validates()),
                        TextEntry::make('validation_last_success_at')
                            ->label(__('webhooks.validation.fields.last_success_at'))
                            ->state(static fn (WebhookEndpoint $record): ?string => $record->validation_last_success_at !== null ? self::panelDate($record->validation_last_success_at) : null)
                            ->placeholder(__('webhooks.validation.page.never'))
                            ->visible(static fn (WebhookEndpoint $record): bool => $record->validates()),
                        // Plan 15.8.7: rejection rate, failure rate and p95 latency
                        // of the real calls of the last days.
                        TextEntry::make('validation_metrics_rejection_rate')
                            ->label(__('webhooks.validation.metrics.rejection_rate'))
                            ->state(static function (): ?string {
                                $metrics = app(ValidationCallMetrics::class)->current();

                                $rate = $metrics->rejectionRate();

                                return $rate === null ? null : self::translated('webhooks.validation.metrics.percent', ['value' => $rate, 'count' => (string) $metrics->rejected]);
                            })
                            ->placeholder(__('webhooks.validation.metrics.no_calls'))
                            ->visible(static fn (WebhookEndpoint $record): bool => $record->validates()),
                        TextEntry::make('validation_metrics_failure_rate')
                            ->label(__('webhooks.validation.metrics.failure_rate'))
                            ->state(static function (): ?string {
                                $metrics = app(ValidationCallMetrics::class)->current();

                                $rate = $metrics->failureRate();

                                return $rate === null ? null : self::translated('webhooks.validation.metrics.percent', ['value' => $rate, 'count' => (string) $metrics->failed]);
                            })
                            ->placeholder(__('webhooks.validation.metrics.no_calls'))
                            ->visible(static fn (WebhookEndpoint $record): bool => $record->validates()),
                        TextEntry::make('validation_metrics_p95_latency')
                            ->label(__('webhooks.validation.metrics.p95_latency'))
                            ->state(static function (): ?string {
                                $metrics = app(ValidationCallMetrics::class)->current();

                                return $metrics->p95LatencyMs === null ? null : self::translated('webhooks.validation.metrics.milliseconds', ['value' => (string) $metrics->p95LatencyMs]);
                            })
                            ->placeholder(__('webhooks.validation.metrics.no_calls'))
                            ->visible(static fn (WebhookEndpoint $record): bool => $record->validates()),
                        TextEntry::make('validation_alert')
                            ->label(__('webhooks.validation.alert.heading'))
                            ->state(static fn (WebhookEndpoint $record): string => __('webhooks.validation.alert.description', [
                                'failures' => $record->validation_consecutive_failures,
                                'policy' => $record->validation_failure_policy->label(),
                                'date' => $record->validation_last_failure_at !== null ? self::panelDate($record->validation_last_failure_at) : '—',
                            ]))
                            ->color('danger')
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->visible(static fn (WebhookEndpoint $record): bool => $record->isValidationFailing())
                            ->columnSpanFull(),
                        TextEntry::make('previous_secret_expires_at')
                            ->label(__('webhooks.fields.previous_secret'))
                            ->state(static fn (WebhookEndpoint $record): ?string => self::previousSecretLine($record->previous_secret_expires_at))
                            ->visible(static fn (WebhookEndpoint $record): bool => $record->previous_secret_expires_at?->isFuture() === true)
                            ->columnSpanFull(),
                        TextEntry::make('created_at')->label(__('webhooks.fields.created_at'))->dateTime(),
                    ]),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class, ValidationCallsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEndpoints::route('/'),
            'view' => ViewWebhookEndpoint::route('/{record}'),
        ];
    }

    /**
     * The create and edit form (plan 15.1): URL, description, and every
     * event or a chosen list of the catalog.
     *
     * @return list<Component>
     */
    public static function formSchema(): array
    {
        return [
            TextInput::make('url')
                ->label(__('webhooks.fields.url'))
                ->helperText(fn (): string => __('webhooks.fields.url_help.'.TenantPanel::modeKey()))
                ->placeholder('https://')
                ->url()
                ->required()
                ->validationMessages(['required' => __('webhooks.form.url_required')])
                ->maxLength(2048)
                ->extraInputAttributes(['class' => 'font-numeric']),
            TextInput::make('description')
                ->label(__('webhooks.fields.description'))
                ->helperText(__('webhooks.fields.description_help'))
                ->maxLength(config()->integer('axispay.webhooks.description_max')),
            Toggle::make('all_events')
                ->label(__('webhooks.fields.all_events'))
                ->helperText(__('webhooks.fields.all_events_help'))
                ->default(true)
                ->live(),
            CheckboxList::make('events')
                ->label(__('webhooks.fields.events'))
                ->helperText(__('webhooks.fields.events_help'))
                ->options(self::eventOptions())
                ->descriptions(self::eventDescriptions())
                ->in(WebhookEventType::asynchronousValues())
                ->columns(['default' => 1, 'sm' => 2])
                ->required(static fn (Get $get): bool => $get('all_events') !== true && $get('validate_payments') !== true)
                ->validationMessages(['required' => __('webhooks.form.events_required')])
                ->visible(static fn (Get $get): bool => $get('all_events') !== true),
            // The synchronous event is chosen on its own: "every event" never
            // includes it, because it decides whether a payment is charged
            // (ADR-0069).
            Toggle::make('validate_payments')
                ->label(__('webhooks.fields.validate_payments'))
                ->helperText(__('webhooks.fields.validate_payments_help'))
                ->live(),
            Radio::make('validation_failure_policy')
                ->label(__('webhooks.validation.fields.failure_policy'))
                ->helperText(__('webhooks.validation.fields.failure_policy_help', ['seconds' => config()->integer('axispay.pre_payment_validation.timeout_seconds')]))
                ->options(self::policyOptions())
                ->descriptions(self::policyDescriptions())
                ->in(array_map(static fn (ValidationFailurePolicy $policy): string => $policy->value, ValidationFailurePolicy::cases()))
                ->default(ValidationFailurePolicy::FailClosed->value)
                ->required(static fn (Get $get): bool => $get('validate_payments') === true)
                ->visible(static fn (Get $get): bool => $get('validate_payments') === true),
            Toggle::make('validation_enabled_by_default')
                ->label(__('webhooks.validation.fields.enabled_by_default'))
                ->helperText(__('webhooks.validation.fields.enabled_by_default_help'))
                ->visible(static fn (Get $get): bool => $get('validate_payments') === true),
            Reauthentication::field(),
        ];
    }

    /**
     * The form of an existing endpoint.
     *
     * @return array<string, mixed>
     */
    public static function formFill(WebhookEndpoint $endpoint): array
    {
        $all = in_array(WebhookEventType::WILDCARD, $endpoint->enabled_events, true);

        return [
            'url' => $endpoint->url,
            'description' => $endpoint->description,
            'all_events' => $all,
            'events' => $all ? [] : array_values(array_diff($endpoint->enabled_events, [WebhookEventType::PaymentPreValidation->value])),
            'validate_payments' => $endpoint->validates(),
            'validation_failure_policy' => $endpoint->validation_failure_policy->value,
            'validation_enabled_by_default' => $endpoint->validation_enabled_by_default,
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function endpointData(#[SensitiveParameter] array $data): WebhookEndpointData
    {
        $events = [];

        if (($data['all_events'] ?? false) === true) {
            $events = [WebhookEventType::WILDCARD];
        } else {
            foreach (is_array($data['events'] ?? null) ? $data['events'] : [] as $event) {
                if (is_string($event)) {
                    $events[] = $event;
                }
            }
        }

        $validatesPayments = ($data['validate_payments'] ?? false) === true;

        if ($validatesPayments) {
            $events[] = WebhookEventType::PaymentPreValidation->value;
        }

        $description = $data['description'] ?? null;
        $policyValue = is_string($data['validation_failure_policy'] ?? null) ? $data['validation_failure_policy'] : '';

        return new WebhookEndpointData(
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            description: is_string($description) ? $description : null,
            events: $events,
            validationFailurePolicy: ValidationFailurePolicy::tryFrom($policyValue) ?? ValidationFailurePolicy::FailClosed,
            validationEnabledByDefault: $validatesPayments && ($data['validation_enabled_by_default'] ?? false) === true,
        );
    }

    /**
     * Re-authentication first when `$reauthenticate`, then the Webhooks
     * action; refusals become errors on the form's fields (`$fields`) or a
     * notification.
     *
     * @template TResult
     *
     * @param  array<mixed>  $data
     * @param  callable(): TResult  $callback
     * @param  list<string>  $fields  the fields of the action's form
     * @return TResult
     */
    public static function run(Action $action, #[SensitiveParameter] array $data, callable $callback, bool $reauthenticate = true, array $fields = []): mixed
    {
        try {
            if ($reauthenticate) {
                Reauthentication::confirm($data);
            }

            return $callback();
        } catch (ReauthenticationRequiredException) {
            DomainErrors::stop(__('webhooks.errors.reauthentication_required'));
        } catch (UnsafeDestinationException $e) {
            self::fieldOrStop($action, $fields, 'url', $e->userMessage());
        } catch (WebhookEndpointNotAllowedException $e) {
            match ($e->reason) {
                WebhookEndpointRefusal::DescriptionTooLong => self::fieldOrStop($action, $fields, 'description', $e->userMessage()),
                WebhookEndpointRefusal::NoEvents, WebhookEndpointRefusal::UnknownEvent => self::fieldOrStop($action, $fields, 'events', $e->userMessage()),
                default => DomainErrors::stop($e->userMessage()),
            };
        }
    }

    /** "Send test event" (plan 15.1): a `ping` sent now; the result opens in a dialog. */
    public static function sendTestAction(): Action
    {
        return Action::make('sendTest')
            ->label(__('webhooks.actions.send_test'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->authorize('sendTest')
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading(__('webhooks.actions.send_test_heading'))
            ->modalDescription(static fn (WebhookEndpoint $record): string => $record->validates()
                ? __('webhooks.actions.send_test_help_both', ['host' => $record->host(), 'seconds' => config()->integer('axispay.pre_payment_validation.timeout_seconds')])
                : __('webhooks.actions.send_test_help', ['host' => $record->host()]))
            ->modalSubmitActionLabel(__('webhooks.actions.send_test_submit'))
            // One test button for both roles of the endpoint (ADR-0069): the
            // choice only appears when the endpoint receives the validation.
            ->schema(static fn (WebhookEndpoint $record): array => $record->validates() ? [
                Radio::make('test_event')
                    ->label(__('webhooks.fields.send_test_event'))
                    ->options([
                        WebhookEventType::Ping->value => __('webhooks.fields.send_test_event_ping'),
                        WebhookEventType::PaymentPreValidation->value => __('webhooks.fields.send_test_event_validation'),
                    ])
                    ->default(WebhookEventType::Ping->value)
                    ->required(),
            ] : [])
            ->action(static function (array $data, WebhookEndpoint $record, Action $action, PresentsTestResults $livewire): void {
                $sendsValidation = ($data['test_event'] ?? null) === WebhookEventType::PaymentPreValidation->value && $record->validates();

                if ($sendsValidation) {
                    $result = self::run($action, [], static fn (): ValidationTestResult => app(TestValidationEndpoint::class)->handle(TenantPanel::user(), $record), reauthenticate: false);

                    $livewire->showTestResult(TestResultPresenter::validation($result), __('webhooks.test_result.validation_heading', ['host' => $record->host()]));

                    return;
                }

                $delivery = self::run($action, [], static fn () => app(SendTestWebhook::class)->handle(TenantPanel::user(), $record), reauthenticate: false);

                $livewire->showTestResult(TestResultPresenter::delivery($delivery), __('webhooks.test_result.webhook_heading', ['host' => $record->host()]));
            });
    }

    /**
     * The endpoint's events as labels: one "All events" badge, or the types.
     *
     * @return list<string>
     */
    public static function eventLabels(WebhookEndpoint $endpoint): array
    {
        if (in_array(WebhookEventType::WILDCARD, $endpoint->enabled_events, true)) {
            // The wildcard never covers the synchronous validation, which may
            // be chosen next to it.
            return $endpoint->validates()
                ? [__('webhooks.all_events'), WebhookEventType::PaymentPreValidation->value]
                : [__('webhooks.all_events')];
        }

        return array_values($endpoint->enabled_events);
    }

    /** `Failing since 3 Oct 2026, 14:30`, or null while the endpoint is healthy. */
    public static function failingLine(WebhookEndpoint $endpoint): ?string
    {
        if ($endpoint->failing_since === null) {
            return null;
        }

        return __('webhooks.failing_since', ['date' => self::panelDate($endpoint->failing_since)]);
    }

    /**
     * The health line of an endpoint that is not failing: a new endpoint
     * with no automatic delivery yet says so instead of claiming health.
     */
    public static function healthPlaceholder(WebhookEndpoint $endpoint): string
    {
        $delivered = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->where('trigger', WebhookDeliveryTrigger::Automatic->value)
            ->exists();

        return $delivered ? __('webhooks.fields.healthy') : __('webhooks.fields.no_deliveries');
    }

    /** While a rotated secret still signs (24 hours), until when; null otherwise. */
    public static function previousSecretLine(?CarbonImmutable $expiresAt): ?string
    {
        if ($expiresAt === null || ! $expiresAt->isFuture()) {
            return null;
        }

        return __('webhooks.previous_secret_until', ['date' => self::panelDate($expiresAt)]);
    }

    /**
     * Each event labeled by what it means; its code goes below (eventDescriptions()).
     *
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        $options = [];

        foreach (WebhookEventType::asynchronous() as $type) {
            $options[$type->value] = $type->description();
        }

        return $options;
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

    /**
     * The event code, small and in mono, under its description.
     *
     * @return array<string, HtmlString>
     */
    private static function eventDescriptions(): array
    {
        $descriptions = [];

        foreach (WebhookEventType::asynchronous() as $type) {
            $descriptions[$type->value] = new HtmlString('<span class="font-numeric text-xs text-fg-secondary">'.e($type->value).'</span>');
        }

        return $descriptions;
    }

    /**
     * @param  list<string>  $fields
     */
    private static function fieldOrStop(Action $action, array $fields, string $field, string $message): never
    {
        if (in_array($field, $fields, true)) {
            throw ValidationException::withMessages([DomainErrors::fieldPath($action, $field) => $message]);
        }

        DomainErrors::stop($message);
    }

    private static function panelDate(CarbonImmutable $time): string
    {
        return $time->setTimezone(FilamentTimezone::get())->translatedFormat(PanelDefaults::DATE_TIME_FORMAT);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function translated(string $key, array $replace): string
    {
        return __($key, $replace);
    }
}
