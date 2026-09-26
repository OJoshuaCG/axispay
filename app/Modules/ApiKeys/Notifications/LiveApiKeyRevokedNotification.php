<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 22: every owner is told when a LIVE API key is revoked. The message
 * names the key and who revoked it; never the key, its prefix or its hash.
 */
final class LiveApiKeyRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $keyName,
        public readonly string $revokedByName,
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
            ->subject(__('api_keys.mail.live_revoked.subject'))
            ->line(__('api_keys.mail.live_revoked.line', ['name' => $this->keyName, 'user' => $this->revokedByName]))
            ->line(__('api_keys.mail.live_revoked.review'));
    }
}
