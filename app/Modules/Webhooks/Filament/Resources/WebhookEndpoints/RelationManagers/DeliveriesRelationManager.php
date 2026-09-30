<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\RelationManagers;

use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Modules\Webhooks\Actions\ResendWebhookDelivery;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Enums\WebhookDeliveryTrigger;
use App\Modules\Webhooks\Filament\Resources\WebhookEndpoints\WebhookEndpointResource;
use App\Modules\Webhooks\Filament\Support\TestResultPresenter;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The delivery log of an endpoint (plan 15.1, 15.6): one row per attempt,
 * newest first, with a status filter, the answer's details and a manual
 * resend (the same event, the same `webhook-id`, one attempt). Read with
 * `webhooks:manage` (WebhookDeliveryPolicy); resending is authorized on the
 * endpoint and re-checked by ResendWebhookDelivery. Kept 30 days.
 */
final class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('webhooks.deliveries.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof WebhookEndpoint && Gate::allows('viewAny', WebhookDelivery::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->with('event'))
            ->modelLabel(__('webhooks.deliveries.singular'))
            ->pluralModelLabel(__('webhooks.deliveries.plural'))
            ->columns([
                TextColumn::make('event.type')
                    ->label(__('webhooks.deliveries.event'))
                    ->formatStateUsing(static fn (WebhookDelivery $record): string => self::eventType($record))
                    ->fontFamily(FontFamily::Mono)
                    ->description(static fn (WebhookDelivery $record): ?string => $record->event?->prefixedId())
                    ->extraAttributes(['class' => 'break-all'])
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('webhooks.deliveries.status'))
                    ->badge()
                    ->formatStateUsing(static fn (WebhookDelivery $record): string => $record->status->label())
                    ->color(static fn (WebhookDelivery $record): string => $record->status->color())
                    ->icon(static fn (WebhookDelivery $record): Heroicon => $record->status->icon())
                    ->description(static fn (WebhookDelivery $record): ?string => $record->error?->label()),
                TextColumn::make('attempt_number')
                    ->label(__('webhooks.deliveries.attempt'))
                    ->formatStateUsing(static fn (WebhookDelivery $record): string => __('webhooks.deliveries.attempt_value', ['number' => $record->attempt_number, 'trigger' => $record->trigger->label()]))
                    ->visibleFrom('md'),
                TextColumn::make('response_status')
                    ->label(__('webhooks.deliveries.http_status'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('duration_ms')
                    ->label(__('webhooks.deliveries.latency'))
                    ->formatStateUsing(static fn (WebhookDelivery $record): string => TestResultPresenter::latency($record->duration_ms))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->alignEnd()
                    ->visibleFrom('lg'),
                TextColumn::make('created_at')
                    ->label(__('webhooks.deliveries.time'))
                    ->dateTime()
                    ->description(static function (WebhookDelivery $record): ?string {
                        if ($record->next_retry_at === null || $record->status !== WebhookDeliveryStatus::Failed) {
                            return null;
                        }

                        return __('webhooks.deliveries.next_retry', ['since' => $record->next_retry_at->diffForHumans()]);
                    }),
            ])
            // ULIDs sort by creation time: newest first.
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('webhooks.deliveries.status'))
                    ->options(WebhookDeliveryStatus::options()),
            ])
            ->filtersLayout(FiltersLayout::Modal)
            ->emptyStateIcon(Heroicon::OutlinedInboxStack)
            ->emptyStateHeading(__('webhooks.deliveries.empty_heading'))
            ->emptyStateDescription(__('webhooks.deliveries.empty_description'))
            ->headerActions([])
            ->recordActions([$this->detailsAction(), $this->resendAction()]);
    }

    private function detailsAction(): Action
    {
        return Action::make('details')
            ->label(__('webhooks.deliveries.details'))
            ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->color('gray')
            ->modalHeading(static fn (WebhookDelivery $record): string => __('webhooks.deliveries.details_heading', ['type' => self::eventType($record)]))
            ->modalWidth('2xl')
            ->schema(static fn (WebhookDelivery $record): array => [
                View::make('filament.webhooks.test-result')->viewData(['result' => TestResultPresenter::delivery($record)]),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('webhooks.test_result.close'));
    }

    private function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('webhooks.deliveries.resend'))
            ->icon(Heroicon::OutlinedArrowUturnRight)
            ->visible(fn (WebhookDelivery $record): bool => $record->trigger !== WebhookDeliveryTrigger::Test
                && $record->status->isFinal()
                && Gate::allows('resend', $this->endpoint()))
            ->requiresConfirmation()
            ->modalHeading(__('webhooks.deliveries.resend_heading'))
            ->modalDescription(fn (WebhookDelivery $record): string => __('webhooks.deliveries.resend_help', ['type' => self::eventType($record), 'host' => $this->endpoint()->host()]))
            ->modalSubmitActionLabel(__('webhooks.deliveries.resend_submit'))
            ->action(function (WebhookDelivery $record, Action $action): void {
                WebhookEndpointResource::run(
                    $action,
                    [],
                    static fn (): WebhookDelivery => app(ResendWebhookDelivery::class)->handle(TenantPanel::user(), $record),
                    reauthenticate: false,
                );

                Notification::make()->success()->title(__('webhooks.notifications.resent'))->send();
            });
    }

    private static function eventType(WebhookDelivery $delivery): string
    {
        return $delivery->event->type->value ?? '—';
    }

    private function endpoint(): WebhookEndpoint
    {
        $owner = $this->getOwnerRecord();
        assert($owner instanceof WebhookEndpoint);

        return $owner;
    }
}
