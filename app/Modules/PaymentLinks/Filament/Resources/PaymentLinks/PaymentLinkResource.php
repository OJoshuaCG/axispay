<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Filament\Resources\PaymentLinks;

use App\Modules\Checkout\Actions\UnblockCheckout;
use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Modules\PayerFields\Enums\PayerFieldRequirement;
use App\Modules\PaymentLinks\Actions\CancelPaymentLink;
use App\Modules\PaymentLinks\Enums\DisputeStatus;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Enums\RefundStatus;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ListPaymentLinks;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\PaymentLinks\Services\CancelPaymentLinkInputParser;
use App\Modules\PaymentLinks\Services\PaymentLinkUrl;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\MoneyDisplay;
use App\Modules\Tenancy\Enums\CheckoutLocale;
use App\Support\Filament\Concerns\SentenceCaseLabels;
use App\Support\Filament\DomainErrors;
use App\Support\Filament\PanelDefaults;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

/**
 * Tenant panel: payment links of the current mode (plan 27 Phase 3;
 * `links:read`, `links:create`, `links:cancel`). Presentation only: creation
 * (ListPaymentLinks header action) and cancellation (link detail) go through
 * the same actions as the API. Another tenant's link is a 404 (tenant
 * scope); links of the other mode never show (BelongsToMode).
 *
 * Below the `md` breakpoint the table shows one summary column (description,
 * amount, status, expiry) instead of the desktop columns.
 */
final class PaymentLinkResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = PaymentLink::class;

    protected static ?string $recordTitleAttribute = 'description';

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 1;

    /** Breadcrumb title: the description, shortened. */
    public const int RECORD_TITLE_LIMIT = 40;

    public static function getModelLabel(): string
    {
        return __('payment_links.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment_links.plural');
    }

    public static function getNavigationGroup(): string
    {
        return __('payment_links.navigation.group');
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof PaymentLink ? Str::limit($record->description, self::RECORD_TITLE_LIMIT) : self::getModelLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ViewColumn::make('summary')
                    ->label(__('payment_links.fields.link'))
                    ->view('filament.payment-links.summary-column')
                    ->hiddenFrom('md'),
                TextColumn::make('description')
                    ->label(__('payment_links.fields.description'))
                    ->searchable()
                    ->limit(60)
                    ->wrap()
                    ->visibleFrom('md'),
                self::amountColumn()->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('payment_links.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (PaymentLinkStatus $state): string => $state->label())
                    ->color(static fn (PaymentLinkStatus $state): string => $state->color())
                    ->icon(static fn (PaymentLinkStatus $state): Heroicon => $state->icon())
                    ->visibleFrom('md'),
                TextColumn::make('client_reference_id')
                    ->label(__('payment_links.fields.client_reference_id'))
                    // Prefix match, so the search can use the reference index.
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query->where('client_reference_id', 'like', addcslashes($search, '%_\\').'%'))
                    ->placeholder('—')
                    ->toggleable()
                    ->visibleFrom('lg'),
                TextColumn::make('expires_at')
                    ->label(__('payment_links.fields.expires_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->visibleFrom('md'),
                TextColumn::make('created_at')
                    ->label(__('payment_links.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->visibleFrom('xl'),
            ])
            // ULIDs sort by creation time: newest first, read in index order.
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payment_links.fields.status'))
                    ->options(PaymentLinkStatus::options()),
                SelectFilter::make('currency')
                    ->label(__('payment_links.fields.currency'))
                    ->options(CurrencyCode::options()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedLink)
            ->emptyStateHeading(__('payment_links.empty.heading'))
            ->emptyStateDescription(__('payment_links.empty.description'))
            ->emptyStateActions([
                // Opens the header's create form (same modal, same rules).
                Action::make('createFromEmptyState')
                    ->label(__('payment_links.actions.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->authorize('create', PaymentLink::class)
                    ->outlined()
                    ->alpineClickHandler("\$wire.mountAction('create')"),
            ])
            // A modal, not a dropdown: at 320-375px a dropdown hugs the screen edge.
            ->filtersLayout(FiltersLayout::Modal)
            // Rows open the link (ADR-0044); canceling is done from the detail.
            ->recordActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(static fn (PaymentLink $record): string => __('payments.checkout_block.callout', ['date' => $record->checkout_blocked_until !== null ? self::panelDate($record->checkout_blocked_until) : '']))
                ->description(__('payments.checkout_block.callout_help'))
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('warning')
                ->visible(static fn (PaymentLink $record): bool => $record->isCheckoutBlocked())
                ->columnSpanFull(),
            Callout::make(static fn (PaymentLink $record): string => self::terminalHeading($record))
                ->description(static fn (PaymentLink $record): ?string => $record->status === PaymentLinkStatus::Canceled ? $record->cancel_reason : null)
                ->icon(static fn (PaymentLink $record): Heroicon => $record->status->icon())
                ->visible(static fn (PaymentLink $record): bool => in_array($record->status, [PaymentLinkStatus::Expired, PaymentLinkStatus::Canceled], true))
                ->columnSpanFull(),
            Section::make()
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        TextEntry::make('status')
                            ->label(__('payment_links.fields.status'))
                            ->badge()
                            ->formatStateUsing(static fn (PaymentLinkStatus $state): string => $state->label())
                            ->color(static fn (PaymentLinkStatus $state): string => $state->color())
                            ->icon(static fn (PaymentLinkStatus $state): Heroicon => $state->icon()),
                        TextEntry::make('expires_at')
                            ->label(__('payment_links.fields.expires'))
                            ->dateTime()
                            // The relative time is text too, not only a tooltip.
                            ->belowContent(static fn (PaymentLink $record): string => $record->expires_at->diffForHumans())
                            ->visible(static fn (PaymentLink $record): bool => $record->status->isShareable()),
                        TextEntry::make('url')
                            ->label(__('payment_links.fields.url'))
                            ->state(static fn (PaymentLink $record): string => PaymentLinkUrl::for($record))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage(__('payment_links.copied'))
                            ->helperText(__('payment_links.url_help'))
                            ->extraAttributes(['class' => 'break-all'])
                            ->visible(static fn (PaymentLink $record): bool => $record->status->isShareable())
                            ->columnSpanFull(),
                        TextEntry::make('client_reference_id')
                            ->label(__('payment_links.fields.client_reference_id'))
                            ->visible(static fn (PaymentLink $record): bool => filled($record->client_reference_id)),
                        TextEntry::make('created_at')->label(__('payment_links.fields.created_at'))->dateTime(),
                    ]),
                ]),
            self::attemptsSection(),
            Section::make(__('payment_links.sections.details'))
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        TextEntry::make('public_id')
                            ->label(__('payment_links.fields.id'))
                            ->state(static fn (PaymentLink $record): string => $record->prefixedId())
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            // Click-to-copy on the value is mouse-only: a real button too.
                            ->hintAction(static fn (PaymentLink $record): Action => self::copyAction('copyId', __('payment_links.actions.copy_id'), $record->prefixedId())->iconButton())
                            ->extraAttributes(['class' => 'break-all']),
                        TextEntry::make('created_via')
                            ->label(__('payment_links.fields.created_via'))
                            ->formatStateUsing(static fn (PaymentLink $record): string => $record->created_via->label()),
                        TextEntry::make('locale')
                            ->label(__('payment_links.fields.locale'))
                            ->formatStateUsing(static fn (string $state): string => CheckoutLocale::tryFrom($state)?->label() ?? $state),
                        TextEntry::make('fx_mode')
                            ->label(__('payment_links.fields.fx_mode'))
                            ->formatStateUsing(static fn (PaymentLink $record): string => $record->fx_mode->label()),
                        TextEntry::make('return_url')
                            ->label(__('payment_links.fields.return_url'))
                            ->extraAttributes(['class' => 'break-all'])
                            ->visible(static fn (PaymentLink $record): bool => filled($record->return_url)),
                        TextEntry::make('payer_fields_config')
                            ->label(__('payment_links.fields.payer_fields'))
                            ->state(static fn (PaymentLink $record): array => self::payerFieldLines($record))
                            ->listWithLineBreaks()
                            ->visible(static fn (PaymentLink $record): bool => self::payerFieldLines($record) !== []),
                        TextEntry::make('metadata')
                            ->label(__('payment_links.fields.metadata'))
                            ->state(static fn (PaymentLink $record): array => self::metadataLines($record))
                            ->listWithLineBreaks()
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(['class' => 'break-all'])
                            ->visible(static fn (PaymentLink $record): bool => self::metadataLines($record) !== []),
                        TextEntry::make('paid_at')->label(__('payment_links.fields.paid_at'))->dateTime()
                            ->visible(static fn (PaymentLink $record): bool => $record->paid_at !== null),
                        TextEntry::make('canceled_at')->label(__('payment_links.fields.canceled_at'))->dateTime()
                            ->visible(static fn (PaymentLink $record): bool => $record->canceled_at !== null),
                        TextEntry::make('cancel_reason')->label(__('payment_links.fields.cancel_reason'))
                            ->visible(static fn (PaymentLink $record): bool => filled($record->cancel_reason)),
                        TextEntry::make('expired_at')->label(__('payment_links.fields.expired_at'))->dateTime()
                            ->visible(static fn (PaymentLink $record): bool => $record->expired_at !== null),
                        TextEntry::make('open_count')->label(__('payment_links.fields.open_count')),
                        TextEntry::make('refund_status')
                            ->label(__('payment_links.fields.refund_status'))
                            ->formatStateUsing(static fn (PaymentLink $record): string => $record->refund_status->label())
                            ->visible(static fn (PaymentLink $record): bool => $record->refund_status !== RefundStatus::None),
                        TextEntry::make('dispute_status')
                            ->label(__('payment_links.fields.dispute_status'))
                            ->formatStateUsing(static fn (PaymentLink $record): string => $record->dispute_status->label())
                            ->visible(static fn (PaymentLink $record): bool => $record->dispute_status !== DisputeStatus::None),
                    ]),
                ]),
        ]);
    }

    /**
     * Payment attempts of the link (Phase 4, ADR-0051), read-only, for users
     * with `payments:read`. The Stripe payment ID is shown here for support
     * only; it never leaves the panel (ADR-019).
     */
    private static function attemptsSection(): Section
    {
        return Section::make(__('payments.attempts.section'))
            ->columnSpanFull()
            ->visible(static fn (): bool => Gate::allows('viewAny', PaymentAttempt::class))
            ->schema([
                TextEntry::make('attempts_empty')
                    ->hiddenLabel()
                    ->state(__('payments.attempts.empty'))
                    ->visible(static fn (PaymentLink $record): bool => ! $record->attempts()->exists()),
                RepeatableEntry::make('attempts')
                    ->hiddenLabel()
                    ->visible(static fn (PaymentLink $record): bool => $record->attempts()->exists())
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            TextEntry::make('status')
                                ->label(__('payments.attempts.status'))
                                ->badge()
                                ->formatStateUsing(static fn (PaymentAttemptStatus $state): string => $state->label())
                                ->color(static fn (PaymentAttemptStatus $state): string => $state->color())
                                ->icon(static fn (PaymentAttemptStatus $state): Heroicon => $state->icon()),
                            TextEntry::make('amount_minor')
                                ->label(__('payments.attempts.amount'))
                                ->formatStateUsing(static fn (PaymentAttempt $record): string => MoneyDisplay::format($record->money()))
                                ->fontFamily(FontFamily::Mono),
                            TextEntry::make('card')
                                ->label(__('payments.attempts.card'))
                                ->state(static fn (PaymentAttempt $record): ?string => $record->card_last4 !== null ? self::cardLine($record) : null)
                                ->placeholder('—'),
                            TextEntry::make('card_country')->label(__('payments.attempts.card_country'))->placeholder('—'),
                            TextEntry::make('failure_count')->label(__('payments.attempts.failures')),
                            TextEntry::make('last_decline_code')
                                ->label(__('payments.attempts.last_decline'))
                                ->state(static fn (PaymentAttempt $record): ?string => $record->last_decline_code ?? $record->last_failure_code)
                                ->fontFamily(FontFamily::Mono)
                                ->placeholder('—'),
                            TextEntry::make('late_payment')
                                ->label(__('payments.attempts.late_payment'))
                                ->state(static fn (PaymentAttempt $record): string => __('payments.attempts.late_payment_yes'))
                                ->visible(static fn (PaymentAttempt $record): bool => $record->late_payment),
                            TextEntry::make('public_id')
                                ->label(__('payments.attempts.id'))
                                ->state(static fn (PaymentAttempt $record): string => $record->prefixedId())
                                ->fontFamily(FontFamily::Mono)
                                ->extraAttributes(['class' => 'break-all']),
                            TextEntry::make('provider_payment_id')
                                ->label(__('payments.attempts.provider_payment_id'))
                                ->fontFamily(FontFamily::Mono)
                                ->extraAttributes(['class' => 'break-all'])
                                ->placeholder('—'),
                            TextEntry::make('created_at')->label(__('payments.attempts.created_at'))->dateTime(),
                            TextEntry::make('succeeded_at')->label(__('payments.attempts.succeeded_at'))->dateTime()
                                ->visible(static fn (PaymentAttempt $record): bool => $record->succeeded_at !== null),
                        ]),
                    ]),
            ]);
    }

    /** `Visa •••• 4242` */
    private static function cardLine(PaymentAttempt $record): string
    {
        $line = __('payments.attempts.card_value', ['brand' => ucfirst((string) $record->card_brand), 'last4' => (string) $record->card_last4]);

        return is_string($line) ? $line : (string) $record->card_last4;
    }

    /** Header action of the detail: lifts the card-testing block (plan 11.7 rule 4). */
    public static function unblockCheckoutAction(): Action
    {
        return Action::make('unblockCheckout')
            ->label(__('payments.checkout_block.unblock'))
            ->icon(Heroicon::OutlinedLockOpen)
            ->authorize('unblockCheckout')
            ->visible(static fn (PaymentLink $record): bool => $record->isCheckoutBlocked())
            ->requiresConfirmation()
            ->modalHeading(__('payments.checkout_block.unblock_heading'))
            ->modalDescription(__('payments.checkout_block.unblock_help'))
            ->modalSubmitActionLabel(__('payments.checkout_block.unblock'))
            ->action(static function (PaymentLink $record): void {
                app(UnblockCheckout::class)->handleForUser(TenantPanel::user(), $record);

                Notification::make()->success()->title(__('payments.checkout_block.unblocked'))->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentLinks::route('/'),
            'view' => ViewPaymentLink::route('/{record}'),
        ];
    }

    /** Header action of the detail: copies the public URL (only while shareable). */
    public static function copyLinkAction(): Action
    {
        return Action::make('copyLink')
            ->label(__('payment_links.actions.copy_link'))
            ->icon(Heroicon::OutlinedClipboardDocument)
            ->tooltip(__('payment_links.actions.copy_link_tooltip'))
            ->visible(static fn (PaymentLink $record): bool => $record->status->isShareable())
            // The public URL is on the page already; copying needs no server call.
            // A notification also tells screen readers (the tooltip is not announced).
            ->alpineClickHandler(static fn (PaymentLink $record): string => self::clipboardJs(PaymentLinkUrl::for($record)));
    }

    /** A button that copies `$value` in the browser and confirms it (or says it failed). */
    public static function copyAction(string $name, string $label, string $value): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedClipboardDocument)
            ->alpineClickHandler(self::clipboardJs($value));
    }

    private static function clipboardJs(string $value): string
    {
        return sprintf(
            'window.navigator.clipboard.writeText(%s).then(() => new FilamentNotification().title(%s).success().send()).catch(() => new FilamentNotification().title(%s).danger().send())',
            Js::from($value),
            Js::from(__('payment_links.copied')),
            Js::from(__('payment_links.copy_failed')),
        );
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('payment_links.actions.cancel'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->outlined()
            ->authorize('cancel')
            ->requiresConfirmation()
            ->modalHeading(__('payment_links.actions.cancel_heading'))
            ->modalDescription(static fn (PaymentLink $record): string => __('payment_links.actions.cancel_help', [
                'amount' => MoneyDisplay::format($record->money()),
                'description' => Str::limit($record->description, 60),
            ]))
            ->modalSubmitActionLabel(__('payment_links.actions.cancel_submit'))
            ->modalCancelActionLabel(__('payment_links.actions.cancel_keep'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('payment_links.fields.cancel_reason'))
                    ->helperText(__('payment_links.fields.cancel_reason_help'))
                    ->maxLength(CancelPaymentLinkInputParser::REASON_MAX)
                    ->rows(3),
            ])
            ->action(static function (array $data, PaymentLink $record, Action $action): void {
                try {
                    app(CancelPaymentLink::class)->handleForUser(
                        TenantPanel::user(),
                        $record,
                        app(CancelPaymentLinkInputParser::class)->parse(['reason' => $data['reason'] ?? null]),
                    );
                } catch (ApiException $e) {
                    DomainErrors::fail($action, $e, ['reason']);
                }

                Notification::make()->success()->title(__('payment_links.notifications.canceled'))->send();
            });
    }

    public static function amountColumn(): TextColumn
    {
        return TextColumn::make('amount_minor')
            ->label(__('payment_links.fields.amount'))
            ->formatStateUsing(static fn (PaymentLink $record): string => MoneyDisplay::format($record->money()))
            ->fontFamily(FontFamily::Mono)
            ->alignEnd()
            ->sortable();
    }

    /** `Vence 26 sep 2026, 14:30` in the panel's time zone. */
    public static function expiryLine(PaymentLink $record): string
    {
        return __('payment_links.expires_line', ['date' => self::panelDate($record->expires_at)]);
    }

    private static function terminalHeading(PaymentLink $record): string
    {
        return match ($record->status) {
            PaymentLinkStatus::Expired => __('payment_links.callout.expired', ['date' => self::panelDate($record->expired_at ?? $record->expires_at)]),
            PaymentLinkStatus::Canceled => __('payment_links.callout.canceled', ['date' => self::panelDate($record->canceled_at ?? $record->updated_at ?? $record->expires_at)]),
            default => '',
        };
    }

    private static function panelDate(CarbonImmutable $time): string
    {
        return $time->setTimezone(FilamentTimezone::get())->translatedFormat(PanelDefaults::DATE_TIME_FORMAT);
    }

    /**
     * Only the fields the payer will see (hidden ones are left out).
     *
     * @return list<string>
     */
    private static function payerFieldLines(PaymentLink $record): array
    {
        $lines = [];

        foreach ($record->payer_fields_config as $field => $requirement) {
            if ($requirement !== PayerFieldRequirement::Hidden->value) {
                $lines[] = __('payment_links.payer_field.'.$field).': '.__('payment_links.payer_requirement.'.$requirement);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function metadataLines(PaymentLink $record): array
    {
        $lines = [];

        foreach ($record->metadata ?? [] as $key => $value) {
            $lines[] = "{$key}: {$value}";
        }

        return $lines;
    }
}
