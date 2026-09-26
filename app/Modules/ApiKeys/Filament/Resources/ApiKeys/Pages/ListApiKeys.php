<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Filament\Resources\ApiKeys\Pages;

use App\Modules\ApiKeys\Actions\CreateApiKey;
use App\Modules\ApiKeys\Data\CreateApiKeyData;
use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\ApiKeys\Exceptions\ApiKeyNotAllowedException;
use App\Modules\ApiKeys\Filament\Resources\ApiKeys\ApiKeyResource;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Filament\Concerns\Reauthentication;
use App\Modules\Identity\Filament\Concerns\TenantPanel;
use App\Support\Filament\DomainErrors;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

/**
 * API keys list with the create flow. The new key is shown ONCE, in a modal
 * that can only be closed with "I have copied the key". The plaintext lives
 * in a non-public property: it exists only while the request that created
 * the key renders that modal, and is never part of the Livewire snapshot, so
 * no later request carries it. It cannot be recovered afterwards: only its
 * prefix and last four characters are stored in readable form.
 */
final class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    /** The plaintext of the key just created; this request only (not persisted). */
    private ?string $issuedKey = null;

    /** Name and masked form of the key just created, for the confirmation after the dialog (not secret). */
    #[Locked]
    public ?string $issuedKeyLabel = null;

    public function getSubheading(): string
    {
        return __('api_keys.page.subheading.'.TenantPanel::modeKey());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('api_keys.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', ApiKey::class)
                ->modalHeading(__('api_keys.actions.create'))
                ->modalDescription(fn (): string => __('api_keys.actions.create_help.'.TenantPanel::modeKey()))
                ->modalSubmitActionLabel(__('api_keys.actions.create_submit'))
                ->modalWidth('2xl')
                ->schema([
                    TextInput::make('name')
                        ->label(__('api_keys.fields.name'))
                        ->helperText(__('api_keys.fields.name_help'))
                        ->required()
                        ->validationMessages(['required' => __('api_keys.validation.name_required')])
                        ->maxLength(CreateApiKey::NAME_MAX),
                    CheckboxList::make('scopes')
                        ->label(__('api_keys.fields.scopes'))
                        ->helperText(__('api_keys.fields.scopes_help'))
                        ->options(ApiScope::options())
                        ->default([ApiScope::LinksCreate->value, ApiScope::LinksRead->value, ApiScope::LinksCancel->value])
                        ->in(ApiScope::values())
                        ->columns(['default' => 1, 'sm' => 2])
                        ->required()
                        ->validationMessages(['required' => __('api_keys.validation.scopes_required')]),
                    Reauthentication::field(),
                ])
                ->action(function (array $data, Action $action): void {
                    $scopes = [];

                    foreach (is_array($data['scopes'] ?? null) ? $data['scopes'] : [] as $value) {
                        $scope = is_string($value) ? ApiScope::tryFrom($value) : null;

                        if ($scope !== null) {
                            $scopes[] = $scope;
                        }
                    }

                    try {
                        Reauthentication::confirm($data);
                        $issued = app(CreateApiKey::class)->handle(
                            TenantPanel::user(),
                            new CreateApiKeyData(is_string($data['name'] ?? null) ? $data['name'] : '', $scopes),
                        );
                    } catch (ReauthenticationRequiredException) {
                        DomainErrors::stop(__('api_keys.errors.reauthentication_required'));
                    } catch (ApiKeyNotAllowedException $e) {
                        DomainErrors::fail($action, $e);
                    }

                    $this->issuedKey = $issued->plaintext;
                    $this->issuedKeyLabel = __('api_keys.notifications.created', ['name' => $issued->apiKey->name, 'key' => $issued->apiKey->maskedKey()]);
                    $this->replaceMountedAction('showIssuedKey');
                }),
        ];
    }

    public function showIssuedKeyAction(): Action
    {
        return Action::make('showIssuedKey')
            ->modalHeading(__('api_keys.issued.heading'))
            ->modalDescription(__('api_keys.issued.description'))
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalWidth('2xl')
            ->schema([
                // The key and its copy button, first in the tab order. The
                // button copies the key from the page, never from JavaScript
                // state.
                View::make('filament.api-keys.issued-key')
                    ->viewData(fn (): array => ['key' => $this->issuedKey]),
                Callout::make(__('api_keys.issued.warning_heading'))
                    ->warning()
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->description(fn (): string => __('api_keys.issued.warning.'.TenantPanel::modeKey())),
            ])
            ->modalSubmitActionLabel(__('api_keys.issued.done'))
            ->modalCancelAction(false)
            ->modalCloseButton(false)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->action(function (): void {
                $this->issuedKey = null;

                if ($this->issuedKeyLabel !== null) {
                    Notification::make()->success()->title($this->issuedKeyLabel)->send();
                    $this->issuedKeyLabel = null;
                }
            });
    }
}
