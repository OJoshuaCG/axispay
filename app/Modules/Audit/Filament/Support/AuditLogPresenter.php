<?php

declare(strict_types=1);

namespace App\Modules\Audit\Filament\Support;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

/**
 * Columns, entries and filters shared by the tenant and platform audit log
 * resources (both read-only).
 */
final class AuditLogPresenter
{
    /**
     * @return list<TextColumn>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('created_at')->label(__('audit.fields.created_at'))->dateTime()->sortable(),
            TextColumn::make('action')
                ->label(__('audit.fields.action'))
                ->state(static fn (AuditLog $record): string => AuditAction::tryFrom($record->action)?->label() ?? $record->action)
                ->wrap(),
            TextColumn::make('actor_type')
                ->label(__('audit.fields.actor'))
                ->badge()
                ->state(static fn (AuditLog $record): string => $record->actor_type->label()),
            TextColumn::make('subject_type')->label(__('audit.fields.subject'))->placeholder('—')->toggleable(),
            TextColumn::make('ip')->label(__('audit.fields.ip'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function actionFilter(): SelectFilter
    {
        return SelectFilter::make('action')
            ->label(__('audit.fields.action'))
            ->options(AuditAction::options());
    }

    /**
     * @return list<TextEntry>
     */
    public static function entries(): array
    {
        return [
            TextEntry::make('created_at')->label(__('audit.fields.created_at'))->dateTime(),
            TextEntry::make('action')
                ->label(__('audit.fields.action'))
                ->state(static fn (AuditLog $record): string => AuditAction::tryFrom($record->action)?->label() ?? $record->action),
            TextEntry::make('actor_type')
                ->label(__('audit.fields.actor'))
                ->state(static fn (AuditLog $record): string => $record->actor_type->label()),
            TextEntry::make('actor_id')->label(__('audit.fields.actor_id'))->placeholder('—')->copyable(),
            TextEntry::make('subject_type')->label(__('audit.fields.subject'))->placeholder('—'),
            TextEntry::make('subject_id')->label(__('audit.fields.subject_id'))->placeholder('—')->copyable(),
            TextEntry::make('changes')
                ->label(__('audit.fields.changes'))
                ->state(static fn (AuditLog $record): string => $record->changes === null ? '—' : (string) json_encode($record->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                ->fontFamily('mono')
                ->extraAttributes(['class' => 'whitespace-pre-wrap break-all']),
            TextEntry::make('ip')->label(__('audit.fields.ip'))->placeholder('—'),
            TextEntry::make('user_agent')->label(__('audit.fields.user_agent'))->placeholder('—')->extraAttributes(['class' => 'break-all']),
            TextEntry::make('request_id')->label(__('audit.fields.request_id'))->placeholder('—')->copyable(),
        ];
    }
}
