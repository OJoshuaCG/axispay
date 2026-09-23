<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Notifications;

use App\Modules\Tenancy\Enums\TenantStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 22: owners are told when their tenant enters grace, suspended or
 * closed. No reason text or other internal detail is included.
 */
final class TenantStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly TenantStatus $status)
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
            ->subject(__('tenancy.mail.status_changed.subject'))
            ->line(__('tenancy.mail.status_changed.line', ['status' => $this->status->label()]))
            ->line(__('tenancy.mail.status_changed.contact'));
    }
}
