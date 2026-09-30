<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Notifications;

use App\Modules\Webhooks\Enums\ValidationEndpointChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 15.8.1 / 15.8.5 / 17.3: the pre-payment validation URL was
 * configured, changed, rotated or removed, or its last calls failed in a row
 * (at most one e-mail per hour). Only the URL's host and the mode; never the
 * full URL (it may carry the merchant's tokens) or the secret.
 */
final class ValidationEndpointNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ValidationEndpointChange $change,
        public readonly string $host,
        public readonly bool $livemode,
        public readonly int $failures = 0,
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
        $mode = __('gateways.mode.'.($this->livemode ? 'live' : 'test'));
        $key = 'webhooks.validation.mail.'.$this->change->value;

        return (new MailMessage)
            ->subject(__($key.'.subject', ['mode' => $mode]))
            ->line(__($key.'.line', ['host' => $this->host, 'mode' => $mode, 'failures' => $this->failures]))
            ->line(__('webhooks.validation.mail.footer'));
    }
}
