<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Notifications;

use App\Modules\Webhooks\Enums\WebhookEndpointChange;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 17.3 / 22: a webhook endpoint was created, changed, deleted or
 * disabled after 5 days of failures. Only the URL's host and the mode; never
 * the full URL (it may carry the merchant's tokens) or the secret.
 */
final class WebhookEndpointNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly WebhookEndpointChange $change,
        public readonly string $host,
        public readonly bool $livemode,
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
        $key = 'webhooks.mail.'.$this->change->value;

        return (new MailMessage)
            ->subject(__($key.'.subject', ['mode' => $mode]))
            ->line(__($key.'.line', ['host' => $this->host, 'mode' => $mode]))
            ->line(__('webhooks.mail.footer'));
    }
}
