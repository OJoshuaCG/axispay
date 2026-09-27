<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Http\Presenters\PaymentLinkPresenter;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Services\DomainEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plan 11.6: every page view of an `active` link counts an opening
 * (`open_count`, `first_opened_at`, `last_opened_at`), except link previewers
 * (WhatsApp, Slack... by user agent, `axispay.checkout.bot_user_agents`).
 * `payment_link.opened` is recorded for the Phase 5 webhooks on the first
 * opening and then at most once per `opened_event_debounce_minutes`, with
 * `open_count` and `first_open`.
 */
final readonly class RecordCheckoutOpening
{
    public function __construct(private DomainEventRecorder $events) {}

    public function handle(PaymentLink $link, ?string $userAgent): void
    {
        if ($link->status !== PaymentLinkStatus::Active || self::isPreviewer($userAgent)) {
            return;
        }

        DB::transaction(function () use ($link): void {
            $locked = PaymentLink::query()->lockForUpdate()->find($link->id);

            if ($locked === null || $locked->status !== PaymentLinkStatus::Active) {
                return;
            }

            $now = CarbonImmutable::now();
            $first = $locked->first_opened_at === null;
            $debounce = max(1, config()->integer('axispay.checkout.opened_event_debounce_minutes'));
            $emit = $locked->opened_event_at === null || $locked->opened_event_at->lessThanOrEqualTo($now->subMinutes($debounce));

            $locked->forceFill([
                'open_count' => $locked->open_count + 1,
                'first_opened_at' => $locked->first_opened_at ?? $now,
                'last_opened_at' => $now,
                'opened_event_at' => $emit ? $now : $locked->opened_event_at,
            ])->save();

            if ($emit) {
                $this->events->record(DomainEventType::PaymentLinkOpened, 'payment_link', $locked->id, [
                    'payment_link' => PaymentLinkPresenter::toApi($locked),
                    'open_count' => $locked->open_count,
                    'first_open' => $first,
                ]);
            }
        });
    }

    public static function isPreviewer(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        foreach (config()->array('axispay.checkout.bot_user_agents') as $needle) {
            if (is_string($needle) && $needle !== '' && stripos($userAgent, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
