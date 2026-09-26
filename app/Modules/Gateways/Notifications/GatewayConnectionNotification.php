<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Notifications;

use App\Modules\Gateways\Enums\ConnectionNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 17.3 / 22: Stripe connected or disconnected, account restricted,
 * invalid API keys, key with excessive permissions. Queued after commit; no
 * account identifiers, keys or other sensitive detail in the message.
 */
final class GatewayConnectionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ConnectionNotice $notice,
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
        $key = 'gateways.mail.'.$this->notice->value;

        return (new MailMessage)
            ->subject(__($key.'.subject', ['mode' => $mode]))
            ->line(__($key.'.line', ['mode' => $mode]))
            ->line(__('gateways.mail.footer'));
    }
}
