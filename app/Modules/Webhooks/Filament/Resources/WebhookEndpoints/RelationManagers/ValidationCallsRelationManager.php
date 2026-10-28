<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\RelationManagers;

use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\PaymentLinkResource;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Filament\Support\TestResultPresenter;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The pre-payment validation calls sent to an endpoint (plan 15.8.7,
 * ADR-0069): one row per call, newest first, real and test, with the
 * outcome, the merchant's reason or the failure and the policy applied.
 * Shown only on the endpoint that receives the validation (or that has
 * calls from when it did). Read with `webhooks:manage`. Kept 30 days.
 */
final class ValidationCallsRelationManager extends RelationManager
{
    protected static string $relationship = 'validationCalls';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('webhooks.validation.calls.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof WebhookEndpoint || ! Gate::allows('viewAny', WebhookEndpoint::class)) {
            return false;
        }

        return $ownerRecord->validates() || $ownerRecord->validationCalls()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('link'))
            ->modelLabel(__('webhooks.validation.calls.singular'))
            ->pluralModelLabel(__('webhooks.validation.calls.plural'))
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
            ->emptyStateDescription(__('webhooks.validation.calls.empty_description'));
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
}
