<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * Shows a signing secret ONCE (plan 15.1, 15.8.1), in a dialog that can only
 * be closed with "I have copied the secret". The plaintext lives in a
 * non-public property: it exists only while the request that issued or
 * revealed it renders that dialog, and is never part of the Livewire
 * snapshot, so no later request carries it.
 */
trait ShowsIssuedSecret
{
    /** The plaintext just issued or revealed; this request only (not persisted). */
    private ?string $issuedSecret = null;

    /** `created`, `rotated` or `revealed`: which dialog heading to show (not secret). */
    #[Locked]
    public ?string $issuedSecretMoment = null;

    /** The confirmation shown after the dialog closes (not secret). */
    #[Locked]
    public ?string $issuedSecretNotice = null;

    /**
     * Replaces the running action with the secret dialog.
     *
     * @param  'created'|'rotated'|'revealed'  $moment
     */
    protected function showIssuedSecret(#[SensitiveParameter] string $secret, string $moment, ?string $notice = null): void
    {
        $this->issuedSecret = $secret;
        $this->issuedSecretMoment = $moment;
        $this->issuedSecretNotice = $notice;
        $this->replaceMountedAction('showIssuedSecret');
    }

    public function showIssuedSecretAction(): Action
    {
        return Action::make('showIssuedSecret')
            ->modalHeading(fn (): string => __('webhooks.secret.heading.'.($this->issuedSecretMoment ?? 'created')))
            ->modalDescription(__('webhooks.secret.description'))
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalWidth('2xl')
            ->schema([
                View::make('filament.webhooks.issued-secret')
                    ->viewData(fn (): array => ['secret' => $this->issuedSecret]),
                Callout::make(__('webhooks.secret.warning_heading'))
                    ->warning()
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->description(__('webhooks.secret.warning')),
            ])
            ->modalSubmitActionLabel(__('webhooks.secret.done'))
            ->modalCancelAction(false)
            ->modalCloseButton(false)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->action(function (): void {
                $this->issuedSecret = null;
                $this->issuedSecretMoment = null;

                if ($this->issuedSecretNotice !== null) {
                    Notification::make()->success()->title($this->issuedSecretNotice)->send();
                    $this->issuedSecretNotice = null;
                }
            });
    }
}
