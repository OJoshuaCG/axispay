<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Notifications;

use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Plan 11.7 rule 4 / 22: a link was blocked after repeated declines. No
 * amounts, card or payer data; the link's public ID only, so the tenant can
 * find it in the panel and lift the block. Sent in the tenant's language.
 */
final class CheckoutBlockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $paymentLinkId,
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
        $link = PrefixedId::encode(ResourceType::PaymentLink, $this->paymentLinkId);

        $hours = config()->integer('axispay.checkout.long_block_hours');

        return (new MailMessage)
            ->subject(__('checkout.mail.blocked.subject', ['mode' => $mode]))
            ->line(trans_choice('checkout.mail.blocked.line', $hours, ['link' => $link, 'hours' => $hours]))
            ->line(__('checkout.mail.blocked.action'))
            // The link's detail in the tenant panel, where the block is lifted.
            ->action(__('checkout.mail.blocked.button'), route('filament.app.resources.payment-links.view', ['record' => $this->paymentLinkId]));
    }
}
