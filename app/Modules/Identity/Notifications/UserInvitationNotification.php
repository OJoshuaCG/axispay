<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation e-mail (plan 22). Queued after the transaction commits. The
 * subject carries no PII; the link is signed, expires with the invitation and
 * works once.
 */
final class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $acceptUrl,
        public readonly string $tenantName,
        public readonly string $roleLabel,
        public readonly CarbonImmutable $expiresAt,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity.mail.invitation.subject'))
            ->line(__('identity.mail.invitation.line', ['tenant' => $this->tenantName, 'role' => $this->roleLabel]))
            ->action(__('identity.mail.invitation.action'), $this->acceptUrl)
            ->line(__('identity.mail.invitation.expires', ['hours' => max(1, (int) round(now()->diffInHours($this->expiresAt)))]))
            ->line(__('identity.mail.invitation.ignore'));
    }
}
