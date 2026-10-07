<?php

declare(strict_types=1);

namespace App\Modules\Fx\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Told to the platform superadmins (plan 13.3): a Banxico FIX was held back
 * for review because it moved too far from the previous one, or the newest
 * usable FIX is too old and `banxico_fix` conversions are blocked.
 */
final class FxRateAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const string REQUIRES_REVIEW = 'requires_review';

    public const string STALE = 'stale';

    public function __construct(
        public readonly string $kind,
        public readonly string $rateDate,
        public readonly string $rate,
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
            ->subject(__('fx.mail.'.$this->kind.'.subject'))
            ->line(__('fx.mail.'.$this->kind.'.line', ['date' => $this->rateDate, 'rate' => $this->rate]))
            ->line(__('fx.mail.'.$this->kind.'.action'));
    }
}
