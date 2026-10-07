<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Resources\Payments;

use App\Modules\PaymentLinks\Enums\DisputeStatus;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ValidationOutcome;
use App\Modules\Payments\Filament\Resources\Payments\Pages\ListPayments;
use App\Modules\Payments\Filament\Resources\Payments\Pages\ViewPayment;
use App\Modules\Payments\Filament\Support\PaymentPresenter;
use App\Modules\Payments\Filament\Support\PaymentTimeline;
use App\Modules\Payments\Models\Dispute;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\AttemptDisplay;
use App\Modules\Payments\Services\RefundSummary;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyDisplay;
use App\Support\Filament\Concerns\SentenceCaseLabels;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Tenant panel: payment history of the current mode (ADR-0059, brought
 * forward from Phase 8; `payments:read`). Read-only. One row per payment
 * attempt: an attempt is one gateway payment (plan 9.2) and is what the API
 * calls a `payment` (`pay_...`); a declined card does not create a new one.
 * The status filter defaults to all, so declined and released attempts show
 * too. Another tenant's payment is a 404 (tenant scope); payments of the
 * other mode never show (BelongsToMode). Payer data is not listed (ADR-0059).
 */
final class PaymentResource extends Resource
{
    use SentenceCaseLabels;

    protected static ?string $model = PaymentAttempt::class;

    protected static bool $isGloballySearchable = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'payments';

    public static function getModelLabel(): string
    {
        return __('payments.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payments.resource.plural');
    }

    public static function getNavigationGroup(): string
    {
        return __('payment_links.navigation.group');
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof PaymentAttempt ? $record->prefixedId() : self::getModelLabel();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('link'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('payments.resource.fields.date'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('link.description')
                    ->label(__('payments.resource.fields.link'))
                    ->state(static fn (PaymentAttempt $record): string => $record->link->description ?? '—')
                    ->description(static fn (PaymentAttempt $record): ?string => $record->link?->client_reference_id)
                    ->searchable(query: static fn (Builder $query, string $search): Builder => self::search($query, $search))
                    ->limit(50)
                    ->wrap(),
                TextColumn::make('amount_minor')
                    ->label(__('payments.resource.fields.amount'))
                    ->formatStateUsing(static fn (PaymentAttempt $record): string => MoneyDisplay::format($record->money()))
                    ->fontFamily(FontFamily::Mono)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('payments.resource.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (PaymentAttempt $record): string => $record->status->label())
                    ->color(static fn (PaymentAttempt $record): string => $record->status->color())
                    ->icon(static fn (PaymentAttempt $record): Heroicon => $record->status->icon()),
                TextColumn::make('card_last4')
                    ->label(__('payments.resource.fields.card'))
                    ->state(static fn (PaymentAttempt $record): ?string => PaymentPresenter::card($record))
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('validation_outcome')
                    ->label(__('payments.resource.fields.validation'))
                    ->badge()
                    ->formatStateUsing(static fn (PaymentAttempt $record): ?string => $record->validation_outcome?->label())
                    ->color(static fn (PaymentAttempt $record): string => $record->validation_outcome?->color() ?? 'gray')
                    ->placeholder('—')
                    ->visibleFrom('lg'),
            ])
            // ULIDs sort by creation time: newest first, read in index order.
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('payments.resource.fields.status'))
                    ->options(PaymentAttemptStatus::options()),
                SelectFilter::make('currency')
                    ->label(__('payments.resource.fields.currency'))
                    ->options(CurrencyCode::options()),
                SelectFilter::make('validation_outcome')
                    ->label(__('payments.resource.fields.validation'))
                    ->options(ValidationOutcome::options()),
                Filter::make('created')
                    ->schema([
                        DatePicker::make('from')->label(__('payments.resource.filters.from')),
                        DatePicker::make('until')->label(__('payments.resource.filters.until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => self::dateRange($query, $data))
                    ->indicateUsing(static fn (array $data): array => self::dateIndicators($data)),
            ])
            ->filtersLayout(FiltersLayout::Modal)
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('payments.resource.empty.heading'))
            ->emptyStateDescription(__('payments.resource.empty.description'))
            ->recordActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        TextEntry::make('status')
                            ->label(__('payments.resource.fields.status'))
                            ->badge()
                            ->formatStateUsing(static fn (PaymentAttempt $record): string => $record->status->label())
                            ->color(static fn (PaymentAttempt $record): string => $record->status->color())
                            ->icon(static fn (PaymentAttempt $record): Heroicon => $record->status->icon()),
                        TextEntry::make('link')
                            ->label(__('payments.resource.fields.link'))
                            ->state(static fn (PaymentAttempt $record): string => Str::limit($record->link->description ?? '—', 80))
                            ->url(static fn (PaymentAttempt $record): ?string => $record->link !== null && PaymentLinkResource::canView($record->link) ? PaymentLinkResource::getUrl('view', ['record' => $record->link]) : null)
                            ->color('primary'),
                        TextEntry::make('card')
                            ->label(__('payments.resource.fields.card'))
                            ->state(static fn (PaymentAttempt $record): ?string => PaymentPresenter::card($record))
                            ->placeholder('—'),
                        TextEntry::make('card_country')
                            ->label(__('payments.attempts.card_country'))
                            ->formatStateUsing(static fn (?string $state): ?string => AttemptDisplay::countryName($state))
                            ->placeholder('—'),
                        TextEntry::make('validation_outcome')
                            ->label(__('payments.resource.fields.validation'))
                            ->badge()
                            ->formatStateUsing(static fn (PaymentAttempt $record): ?string => $record->validation_outcome?->label())
                            ->color(static fn (PaymentAttempt $record): string => $record->validation_outcome?->color() ?? 'gray')
                            ->belowContent(static function (PaymentAttempt $record): ?string {
                                if ($record->validation_outcome !== ValidationOutcome::FailedOpen) {
                                    return null;
                                }

                                return __('payments.resource.fail_open_help');
                            })
                            ->placeholder('—'),
                        // Shown only once a card was declined: a 0 says nothing.
                        TextEntry::make('failure_count')
                            ->label(__('payments.attempts.failures'))
                            ->visible(static fn (PaymentAttempt $record): bool => $record->failure_count > 0),
                        TextEntry::make('late_payment')
                            ->label(__('payments.attempts.late_payment'))
                            ->state(__('payments.attempts.late_payment_yes'))
                            ->visible(static fn (PaymentAttempt $record): bool => $record->late_payment),
                        TextEntry::make('needs_review')
                            ->label(__('payments.attempts.needs_review'))
                            ->state(static fn (PaymentAttempt $record): string => AttemptDisplay::reviewReason($record->review_reason))
                            ->color('warning')
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->visible(static fn (PaymentAttempt $record): bool => $record->needs_review)
                            ->columnSpanFull(),
                        TextEntry::make('public_id')
                            ->label(__('payments.attempts.id'))
                            ->state(static fn (PaymentAttempt $record): string => $record->prefixedId())
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage(__('payment_links.copied'))
                            ->extraAttributes(['class' => 'break-all']),
                        // For support only; never leaves the panel (ADR-019).
                        TextEntry::make('provider_payment_id')
                            ->label(__('payments.attempts.provider_payment_id'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(['class' => 'break-all'])
                            ->placeholder('—'),
                    ]),
                ]),
            self::refundsSection(),
            Section::make(__('payments.timeline.title'))
                ->columnSpanFull()
                ->schema([
                    View::make('filament.payments.timeline')
                        ->viewData(static fn (PaymentAttempt $record): array => ['entries' => PaymentTimeline::for($record)]),
                ]),
        ]);
    }

    /**
     * What went back to the payer and the disputes opened against the payment
     * (plan 16, ADR-0066): read only, and only when there is something to show.
     * Refunds are requested through the API or in Stripe; disputes are answered
     * in the merchant's own Stripe Dashboard.
     */
    private static function refundsSection(): Section
    {
        return Section::make(__('payments.refunds.section'))
            ->description(__('payments.refunds.help'))
            ->columnSpanFull()
            ->visible(static fn (PaymentAttempt $record): bool => $record->refunds()->exists() || $record->disputes()->exists())
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextEntry::make('amount_refunded')
                        ->label(__('payments.refunds.refunded'))
                        ->state(static fn (PaymentAttempt $record): string => MoneyDisplay::format(Money::ofMinor($record->amount_refunded_minor, $record->currency)))
                        ->fontFamily(FontFamily::Mono),
                    TextEntry::make('refund_status')
                        ->label(__('payments.refunds.refund_status'))
                        ->state(static fn (PaymentAttempt $record): string => RefundSummary::of($record)->label()),
                    TextEntry::make('dispute_status')
                        ->label(__('payments.refunds.dispute_status'))
                        ->state(static fn (PaymentAttempt $record): string => $record->link !== null ? $record->link->dispute_status->label() : DisputeStatus::None->label())
                        ->visible(static fn (PaymentAttempt $record): bool => $record->disputes()->exists()),
                ]),
                RepeatableEntry::make('refunds')
                    ->label(__('payments.refunds.refunds'))
                    ->visible(static fn (PaymentAttempt $record): bool => $record->refunds()->exists())
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('amount_minor')
                            ->label(__('payments.refunds.amount'))
                            ->formatStateUsing(static fn (Refund $record): string => MoneyDisplay::format($record->money()))
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('status')
                            ->label(__('payments.refunds.status'))
                            ->badge()
                            ->formatStateUsing(static fn (Refund $record): string => $record->status->label())
                            ->color(static fn (Refund $record): string => $record->status->color()),
                        TextEntry::make('reason')
                            ->label(__('payments.refunds.reason'))
                            ->formatStateUsing(static fn (Refund $record): string => $record->reason->label()),
                        TextEntry::make('origin')
                            ->label(__('payments.refunds.origin'))
                            ->formatStateUsing(static fn (Refund $record): string => $record->origin->label()),
                        TextEntry::make('created_at')
                            ->label(__('payments.refunds.date'))
                            ->dateTime(),
                    ])
                    ->columns(['default' => 2, 'md' => 5]),
                RepeatableEntry::make('disputes')
                    ->label(__('payments.refunds.disputes'))
                    ->visible(static fn (PaymentAttempt $record): bool => $record->disputes()->exists())
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('amount_minor')
                            ->label(__('payments.refunds.amount'))
                            ->formatStateUsing(static fn (Dispute $record): string => MoneyDisplay::format($record->money()))
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('status')
                            ->label(__('payments.refunds.status'))
                            ->badge()
                            ->formatStateUsing(static fn (Dispute $record): string => $record->status->label())
                            ->color(static fn (Dispute $record): string => $record->status->isOpen() ? 'warning' : 'gray'),
                        TextEntry::make('reason')
                            ->label(__('payments.refunds.reason'))
                            ->placeholder('—'),
                        TextEntry::make('evidence_due_by')
                            ->label(__('payments.refunds.evidence_due_by'))
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('opened_at')
                            ->label(__('payments.refunds.date'))
                            ->dateTime(),
                    ])
                    ->columns(['default' => 2, 'md' => 5]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    /** The heading of the detail: the amount in the numeric font. */
    public static function amountHeading(PaymentAttempt $record, string $languageTag): HtmlString
    {
        return new HtmlString('<span class="amount" lang="'.e($languageTag).'">'.e(MoneyDisplay::format($record->money())).'</span>');
    }

    /**
     * By the link's description or reference, or by the payment ID
     * (`pay_...`). Payer data is encrypted and cannot be searched.
     *
     * @param  Builder<PaymentAttempt>  $query
     * @return Builder<PaymentAttempt>
     */
    private static function search(Builder $query, string $search): Builder
    {
        $search = trim($search);
        $paymentId = PrefixedId::tryParse($search, ResourceType::Payment);

        if ($paymentId !== null) {
            return $query->whereKey($paymentId->ulid);
        }

        $escaped = addcslashes($search, '%_\\');

        return $query->whereHas('link', static fn (Builder $link): Builder => $link
            ->where('description', 'like', '%'.$escaped.'%')
            // Prefix match, so the search can use the reference index.
            ->orWhere('client_reference_id', 'like', $escaped.'%'));
    }

    /**
     * Whole days in the panel's time zone (the tenant's), stored in UTC.
     *
     * @param  Builder<PaymentAttempt>  $query
     * @param  array<mixed>  $data
     * @return Builder<PaymentAttempt>
     */
    private static function dateRange(Builder $query, array $data): Builder
    {
        $timezone = FilamentTimezone::get();
        $from = is_string($data['from'] ?? null) && $data['from'] !== '' ? Carbon::parse($data['from'], $timezone)->startOfDay()->utc() : null;
        $until = is_string($data['until'] ?? null) && $data['until'] !== '' ? Carbon::parse($data['until'], $timezone)->endOfDay()->utc() : null;

        return $query
            ->when($from !== null, static fn (Builder $q): Builder => $q->where('created_at', '>=', $from))
            ->when($until !== null, static fn (Builder $q): Builder => $q->where('created_at', '<=', $until));
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function dateIndicators(array $data): array
    {
        $indicators = [];

        if (is_string($data['from'] ?? null) && $data['from'] !== '') {
            $indicators[] = __('payments.resource.filters.from_indicator', ['date' => $data['from']]);
        }

        if (is_string($data['until'] ?? null) && $data['until'] !== '') {
            $indicators[] = __('payments.resource.filters.until_indicator', ['date' => $data['until']]);
        }

        return $indicators;
    }
}
