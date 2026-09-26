<?php

declare(strict_types=1);

namespace App\Modules\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 17.3 / 22, ADR-0045: the tenant's owners and the promoted user are
 * told when platform support grants the owner role (PromoteToOwner). No
 * person's name or e-mail and no support reason in the message; the tenant's
 * display name identifies the account. Sent in the tenant's default language.
 */
final class OwnerGrantedByPlatformNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $promotedUserId,
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
        $isPromotedUser = $notifiable instanceof Model && $notifiable->getKey() === $this->promotedUserId;

        return (new MailMessage)
            ->subject(__('access.mail.owner_granted.subject'))
            ->line(__($isPromotedUser ? 'access.mail.owner_granted.line_self' : 'access.mail.owner_granted.line', ['tenant' => $this->tenantName]))
            ->line(__('access.mail.owner_granted.review'));
    }
}
