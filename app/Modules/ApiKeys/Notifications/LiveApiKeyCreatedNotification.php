<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 17.3: every owner is told when a LIVE API key is created. The message
 * names the key and who created it; never the key, its prefix or its hash.
 */
final class LiveApiKeyCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $keyName,
        public readonly string $createdByName,
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
            ->subject(__('api_keys.mail.live_created.subject'))
            ->line(__('api_keys.mail.live_created.line', ['name' => $this->keyName, 'user' => $this->createdByName]))
            ->line(__('api_keys.mail.live_created.review'));
    }
}
