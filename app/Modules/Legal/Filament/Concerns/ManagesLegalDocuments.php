<?php

declare(strict_types=1);

namespace App\Modules\Legal\Filament\Concerns;

use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Legal\Data\LegalDocument;
use App\Modules\Legal\Data\LegalDocumentData;
use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Exceptions\InvalidLegalDocumentException;
use App\Modules\Legal\Services\LegalMarkdown;
use App\Support\Filament\DomainErrors;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * The "Legal" pages of both panels (ADR-0056): one section per document
 * (privacy notice, terms) with its state and a preview, and the edit and
 * remove actions. Each page supplies who may act and which domain action
 * saves; no business logic here: the form input is checked by
 * LegalDocumentData and saved by the Legal actions.
 */
trait ManagesLegalDocuments
{
    /** The published document of this kind, if any. */
    abstract protected function currentDocument(LegalDocumentKind $kind): ?LegalDocument;

    /** Hands the checked document to the domain action. */
    abstract protected function saveDocument(LegalDocumentData $data): void;

    abstract protected function removeDocument(LegalDocumentKind $kind): void;

    /**
     * The model class the `manage` ability is checked against.
     *
     * @return class-string
     */
    abstract protected static function policyModel(): string;

    /** Whether changes need the re-authentication window (platform only). */
    abstract protected static function requiresReauthentication(): bool;

    abstract protected function sectionDescription(LegalDocumentKind $kind): string;

    /**
     * Warnings shown while a document is not set, by kind.
     *
     * @return array<string, string>
     */
    abstract protected function missingWarnings(): array;

    /** What removing the document means for payers (the remove modal). */
    abstract protected function removeHelp(LegalDocumentKind $kind): string;

    public function getTitle(): string
    {
        return __('legal.page.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('legal.page.title');
    }

    public function content(Schema $schema): Schema
    {
        $markdown = app(LegalMarkdown::class);
        $sections = [];

        foreach (LegalDocumentKind::cases() as $kind) {
            $document = $this->currentDocument($kind);

            $sections[] = Section::make($kind->label())
                ->description($this->sectionDescription($kind))
                ->icon($kind === LegalDocumentKind::Privacy ? Heroicon::OutlinedShieldCheck : Heroicon::OutlinedDocumentText)
                ->schema([
                    View::make('filament.legal.document-preview')->viewData([
                        'document' => $document,
                        'html' => $document !== null && $document->isText() ? $markdown->render((string) $document->body, 3) : null,
                        'warning' => $document === null ? ($this->missingWarnings()[$kind->value] ?? null) : null,
                    ]),
                    Actions::make([$this->editAction($kind), $this->removeAction($kind)]),
                ]);
        }

        return $schema->components($sections);
    }

    public function editPrivacyAction(): Action
    {
        return $this->editAction(LegalDocumentKind::Privacy);
    }

    public function editTermsAction(): Action
    {
        return $this->editAction(LegalDocumentKind::Terms);
    }

    public function removePrivacyAction(): Action
    {
        return $this->removeAction(LegalDocumentKind::Privacy);
    }

    public function removeTermsAction(): Action
    {
        return $this->removeAction(LegalDocumentKind::Terms);
    }

    private function editAction(LegalDocumentKind $kind): Action
    {
        $isText = static fn (Get $get): bool => $get('format') === LegalDocumentFormat::Text->value;

        $schema = [
            ToggleButtons::make('format')
                ->label(__('legal.fields.format'))
                ->options(LegalDocumentFormat::options())
                ->default(LegalDocumentFormat::Text->value)
                ->inline()
                ->live()
                ->required(),
            Textarea::make('body')
                ->label(__('legal.fields.body'))
                ->helperText(__('legal.fields.body_help', ['max' => number_format(LegalDocumentData::MAX_BODY_LENGTH)]))
                ->rows(16)
                ->maxLength(LegalDocumentData::MAX_BODY_LENGTH)
                ->required($isText)
                ->visible($isText),
            TextInput::make('url')
                ->label(__('legal.fields.url'))
                ->helperText(__('legal.fields.url_help'))
                ->url()
                ->placeholder('https://')
                ->maxLength(LegalDocumentData::MAX_URL_LENGTH)
                ->required(static fn (Get $get): bool => ! $isText($get))
                ->visible(static fn (Get $get): bool => ! $isText($get)),
        ];

        if (static::requiresReauthentication()) {
            $schema[] = Reauthentication::field();
        }

        return Action::make('edit'.ucfirst($kind->value))
            ->label(fn (): string => $this->currentDocument($kind) === null ? __('legal.actions.set') : __('legal.actions.edit'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->authorize('manage', static::policyModel())
            ->modalHeading(__('legal.actions.edit_heading', ['document' => $kind->label()]))
            ->modalSubmitActionLabel(__('legal.actions.save'))
            ->modalWidth('3xl')
            ->fillForm(function () use ($kind): array {
                $document = $this->currentDocument($kind);

                return [
                    'format' => ($document->format ?? LegalDocumentFormat::Text)->value,
                    'body' => $document?->body,
                    'url' => $document?->url,
                ];
            })
            ->schema($schema)
            ->action(function (array $data, Action $action) use ($kind): void {
                $format = LegalDocumentFormat::tryFrom(is_string($data['format'] ?? null) ? $data['format'] : '') ?? LegalDocumentFormat::Text;

                try {
                    $document = LegalDocumentData::from($kind, $format, $data['body'] ?? null, $data['url'] ?? null);
                } catch (InvalidLegalDocumentException $e) {
                    throw ValidationException::withMessages([DomainErrors::fieldPath($action, $e->rejection->field()) => $e->rejection->message()]);
                }

                $this->runGuarded($data, fn () => $this->saveDocument($document));

                $this->refreshContent();
                Notification::make()->success()->title(__('legal.notifications.saved', ['document' => $kind->label()]))->send();
            });
    }

    private function removeAction(LegalDocumentKind $kind): Action
    {
        return Action::make('remove'.ucfirst($kind->value))
            ->label(__('legal.actions.remove'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->outlined()
            ->visible(fn (): bool => $this->currentDocument($kind) !== null)
            ->authorize('manage', static::policyModel())
            ->requiresConfirmation()
            ->modalHeading(__('legal.actions.remove_heading', ['document' => $kind->label()]))
            ->modalDescription($this->removeHelp($kind))
            ->modalSubmitActionLabel(__('legal.actions.remove'))
            ->schema(static::requiresReauthentication() ? [Reauthentication::field()] : [])
            ->action(function (array $data) use ($kind): void {
                $this->runGuarded($data, fn () => $this->removeDocument($kind));

                $this->refreshContent();
                Notification::make()->success()->title(__('legal.notifications.removed', ['document' => $kind->label()]))->send();
            });
    }

    /**
     * Re-authentication first when the page needs it, then the domain action.
     *
     * @param  array<mixed>  $data
     * @param  callable(): void  $callback
     */
    private function runGuarded(array $data, callable $callback): void
    {
        if (! static::requiresReauthentication()) {
            $callback();

            return;
        }

        try {
            Reauthentication::confirm($data);
            $callback();
        } catch (ReauthenticationRequiredException) {
            DomainErrors::stop(__('legal.errors.reauthentication_required'));
        }
    }

    /** A document changed: rebuild the page content on this render. */
    private function refreshContent(): void
    {
        unset($this->cachedSchemas['content']);
    }
}
