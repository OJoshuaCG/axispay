<?php

declare(strict_types=1);

namespace App\Modules\Access\Notifications;

use App\Modules\Access\Enums\SystemRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 17.3 / 22: owners and the affected user are told when a sensitive role
 * is assigned. No names or e-mails in the subject.
 */
final class SensitiveRoleAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SystemRole $role)
    {
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
            ->subject(__('access.mail.sensitive_role.subject'))
            ->line(__('access.mail.sensitive_role.line', ['role' => $this->role->label()]))
            ->line(__('access.mail.sensitive_role.review'));
    }
}
