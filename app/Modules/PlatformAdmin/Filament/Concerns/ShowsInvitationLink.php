<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Filament\Concerns;

use App\Modules\Identity\Data\IssuedInvitation;
use App\Modules\PlatformAdmin\Filament\Support\PlatformPii;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;

/**
 * Shows the accept link of an owner invitation ONCE (ADR-0068), right after
 * it is created or resent, so a superadmin can share it by hand when the
 * e-mail does not arrive. Same rules as ShowsIssuedSecret: the link lives in
 * a non-public property, exists only while the request that issued it renders
 * the dialog, and is never part of the Livewire snapshot. Only the token's
 * hash is stored, so it cannot be shown again; a new link means resending,
 * which invalidates the previous one.
 *
 * The dialog closes only with "I have copied the link". Work that triggers
 * another request to this component (the `tenant-ownership-changed` event)
 * waits for afterInvitationLinkClosed(); that request would re-render the
 * dialog without the link.
 */
trait ShowsInvitationLink
{
    /** The invitation just issued, with its link; this request only (not persisted). */
    private ?IssuedInvitation $issuedInvitation = null;

    /** The confirmation shown after the dialog closes (not secret). */
    #[Locked]
    public ?string $issuedInvitationNotice = null;

    /** Called when the dialog closes: refresh other components, redirect... */
    abstract protected function afterInvitationLinkClosed(): void;

    /** Replaces the running action (if any) with the link dialog. */
    protected function showInvitationLink(IssuedInvitation $issued, string $notice): void
    {
        $this->issuedInvitation = $issued;
        $this->issuedInvitationNotice = $notice;
        $this->replaceMountedAction('showInvitationLink');
    }

    public function showInvitationLinkAction(): Action
    {
        return Action::make('showInvitationLink')
            ->modalHeading(__('platform.tenants.invitations.link.heading'))
            ->modalDescription(fn (): string => __('platform.tenants.invitations.link.description', [
                'email' => PlatformPii::email($this->issuedInvitation?->invitation->email) ?? '—',
                'date' => $this->issuedInvitation?->invitation->expires_at->isoFormat('LLL') ?? '—',
            ]))
            ->modalIcon(Heroicon::OutlinedLink)
            ->modalWidth('2xl')
            ->schema([
                View::make('filament.invitations.issued-link')
                    ->viewData(fn (): array => ['link' => $this->issuedInvitation?->acceptUrl]),
                Callout::make(__('platform.tenants.invitations.link.warning_heading'))
                    ->warning()
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->description(__('platform.tenants.invitations.link.warning')),
            ])
            ->modalSubmitActionLabel(__('platform.tenants.invitations.link.done'))
            ->modalCancelAction(false)
            ->modalCloseButton(false)
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->action(function (): void {
                $this->issuedInvitation = null;

                if ($this->issuedInvitationNotice !== null) {
                    Notification::make()->success()->title($this->issuedInvitationNotice)->send();
                    $this->issuedInvitationNotice = null;
                }

                $this->afterInvitationLinkClosed();
            });
    }
}
