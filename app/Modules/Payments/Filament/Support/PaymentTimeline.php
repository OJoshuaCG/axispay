<?php

declare(strict_types=1);

namespace App\Modules\Payments\Filament\Support;

use App\Modules\Payments\Enums\ValidationOutcome;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptDisplay;
use App\Modules\Webhooks\Enums\ValidationCallOutcome;
use App\Modules\Webhooks\Filament\Pages\PrePaymentValidationSettings;
use App\Modules\Webhooks\Filament\Support\TestResultPresenter;
use App\Modules\Webhooks\Models\DomainEvent;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * The timeline of one payment in the tenant panel (plan 20.2): started,
 * each decline, the card authorization, the pre-payment validation calls,
 * the capture or the release, and the events recorded for the payment with
 * the webhook event each one became. Presentation only; reads the records
 * the payment flow already wrote. The validation calls are listed only for
 * users who may read the validation log (`webhooks:manage`, plan 17.1).
 */
final class PaymentTimeline
{
    private function __construct() {}

    /**
     * Oldest first.
     *
     * @return list<array{time: CarbonImmutable, title: string, detail: ?string, code: ?string, color: string, icon: string}>
     */
    public static function for(PaymentAttempt $attempt): array
    {
        $entries = [self::entry($attempt->created_at ?? CarbonImmutable::now(), __('payments.timeline.started'), null, null, 'gray', 'play-circle')];

        foreach ($attempt->failures()->orderBy('id')->get() as $failure) {
            $code = $failure->decline_code ?? $failure->code;
            $entries[] = self::entry(
                $failure->created_at ?? $attempt->created_at ?? CarbonImmutable::now(),
                __('payments.timeline.declined'),
                $code !== null ? AttemptDisplay::declineLabel($code) : null,
                $code,
                'danger',
                'x-circle',
            );
        }

        if ($attempt->authorized_at !== null) {
            $entries[] = self::entry(
                $attempt->authorized_at,
                __('payments.timeline.authorized'),
                $attempt->capture_before !== null ? self::text('payments.timeline.capture_before', ['date' => PaymentPresenter::date($attempt->capture_before)]) : null,
                null,
                'info',
                'shield-check',
            );
        }

        if (Gate::allows('viewAny', ValidationEndpoint::class)) {
            foreach (ValidationCall::query()->where('payment_attempt_id', $attempt->id)->where('is_test', false)->orderBy('id')->get() as $call) {
                $entries[] = self::entry(
                    $call->created_at ?? $attempt->created_at ?? CarbonImmutable::now(),
                    __('payments.timeline.validation', ['outcome' => $call->outcome?->label() ?? '—']),
                    implode(' · ', array_filter([PrePaymentValidationSettings::callDetail($call), $call->duration_ms !== null ? TestResultPresenter::latency($call->duration_ms) : null])) ?: null,
                    $call->prefixedId(),
                    match ($call->outcome) {
                        ValidationCallOutcome::Approved => 'success',
                        ValidationCallOutcome::Rejected => 'warning',
                        default => 'danger',
                    },
                    'shield-exclamation',
                );
            }
        }

        if ($attempt->succeeded_at !== null) {
            $entries[] = self::entry(
                $attempt->succeeded_at,
                __('payments.timeline.captured'),
                $attempt->validation_outcome === ValidationOutcome::FailedOpen ? self::text('payments.timeline.captured_fail_open') : null,
                null,
                'success',
                'check-circle',
            );
        }

        if ($attempt->canceled_at !== null) {
            $entries[] = self::entry(
                $attempt->canceled_at,
                __('payments.timeline.released'),
                match ($attempt->validation_outcome) {
                    ValidationOutcome::Rejected => self::text('payments.timeline.released_rejected'),
                    ValidationOutcome::FailedClosed => self::text('payments.timeline.released_fail_closed'),
                    default => null,
                },
                null,
                'gray',
                'no-symbol',
            );
        }

        if ($attempt->failed_at !== null) {
            $entries[] = self::entry($attempt->failed_at, __('payments.timeline.failed'), null, null, 'danger', 'x-circle');
        }

        $events = DomainEvent::query()
            ->where('subject_type', 'payment')
            ->where('subject_id', $attempt->id)
            ->orderBy('occurred_at')
            ->get();
        $published = $events->isEmpty() ? collect() : WebhookEvent::query()
            ->whereIn('domain_event_id', $events->modelKeys())
            ->get()
            ->keyBy('domain_event_id');

        foreach ($events as $event) {
            $webhook = $published->get($event->id);
            $entries[] = self::entry(
                $event->occurred_at,
                __('payments.timeline.event', ['type' => $event->type->value]),
                $webhook instanceof WebhookEvent ? __('payments.timeline.event_published') : __('payments.timeline.event_pending'),
                $webhook instanceof WebhookEvent ? $webhook->prefixedId() : null,
                'gray',
                'signal',
            );
        }

        usort($entries, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        return $entries;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        return __($key, $replace);
    }

    /**
     * @return array{time: CarbonImmutable, title: string, detail: ?string, code: ?string, color: string, icon: string}
     */
    private static function entry(CarbonImmutable $time, string $title, ?string $detail, ?string $code, string $color, string $icon): array
    {
        return ['time' => $time, 'title' => $title, 'detail' => $detail, 'code' => $code, 'color' => $color, 'icon' => $icon];
    }
}
