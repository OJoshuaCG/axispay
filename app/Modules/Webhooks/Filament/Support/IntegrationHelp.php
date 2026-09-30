<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Support;

use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Webhooks\Enums\DomainEventType;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Enums\WebhookEventType;
use App\Modules\Webhooks\Services\PrePaymentValidationClient;
use App\Modules\Webhooks\Services\RetrySchedule;
use App\Modules\Webhooks\Services\ValidationPayload;
use App\Modules\Webhooks\Services\ValidationResponseParser;
use App\Modules\Webhooks\Services\WebhookPayload;
use App\Modules\Webhooks\Services\WebhookSigner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use stdClass;

/**
 * The "How it works" dialogs of the webhook endpoints and the pre-payment
 * validation pages: a technical but plain explanation for the merchant's
 * developer. Every number comes from the configuration and every example
 * from the code that builds the real requests (WebhookPayload,
 * ValidationPayload), so the help cannot drift from what is sent.
 * Presentation only; the dialogs change nothing.
 */
final class IntegrationHelp
{
    public const string WEBHOOKS_ACTION = 'webhooksHelp';

    public const string VALIDATION_ACTION = 'validationHelp';

    /** Fixed identifiers and time, so the examples read the same on every visit. */
    private const string EXAMPLE_EVENT_ULID = '01K6DV3W8Q2Z9T5M7C4N1B6X0R';

    private const string EXAMPLE_PAYMENT_ULID = '01K6DV3S2H8F4K1P9W3Q7Y5E2A';

    private const string EXAMPLE_LINK_ULID = '01K6DTZQ5R3J7N2M8V4C6B1X9D';

    private const string EXAMPLE_CALL_ULID = '01K6DV3RZ6E4W2N8T1M5Q9C7B3';

    private const string EXAMPLE_TIME = '2026-09-30T15:04:05Z';

    private function __construct() {}

    /** Header action of the webhook endpoints list and detail. */
    public static function webhooksAction(): Action
    {
        return self::action(
            self::WEBHOOKS_ACTION,
            __('webhooks.help.webhooks.heading'),
            __('webhooks.help.webhooks.description'),
            static fn (): View => view('filament.webhooks.help.webhooks', self::webhooksData()),
        );
    }

    /** Header action of the pre-payment validation page. */
    public static function validationAction(): Action
    {
        return self::action(
            self::VALIDATION_ACTION,
            __('webhooks.help.validation.heading'),
            __('webhooks.help.validation.description'),
            static fn (): View => view('filament.webhooks.help.validation', self::validationData()),
        );
    }

    /**
     * A link-styled shortcut that opens a help dialog from an empty state or
     * a section. It only mounts the page's header action on the client.
     */
    public static function hintAction(string $name, string $helpAction): Action
    {
        return Action::make($name)
            ->label(__('webhooks.help.hint'))
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->link()
            ->color('primary')
            ->alpineClickHandler("\$wire.mountAction('{$helpAction}')");
    }

    /**
     * @param  Closure(): View  $content
     */
    private static function action(string $name, string $heading, string $description, Closure $content): Action
    {
        return Action::make($name)
            ->label(__('webhooks.help.action'))
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('gray')
            ->slideOver()
            ->modalIcon(Heroicon::OutlinedQuestionMarkCircle)
            ->modalHeading($heading)
            ->modalDescription($description)
            ->modalWidth('2xl')
            ->modalContent($content)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('webhooks.help.close'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function webhooksData(): array
    {
        $schedule = app(RetrySchedule::class);
        $attempts = [];

        for ($attempt = 1; $attempt <= $schedule->maxAttempts(); $attempt++) {
            $seconds = (int) $schedule->delayBefore($attempt);
            $attempts[] = $seconds === 0
                ? __('webhooks.help.webhooks.attempt_immediate', ['number' => $attempt])
                : __('webhooks.help.webhooks.attempt_after', ['number' => $attempt, 'wait' => self::duration($seconds)]);
        }

        return [
            'maxEndpoints' => config()->integer('axispay.webhooks.max_endpoints_per_mode'),
            'connectTimeout' => config()->integer('axispay.webhooks.connect_timeout_seconds'),
            'timeout' => config()->integer('axispay.webhooks.timeout_seconds'),
            'attempts' => $schedule->maxAttempts(),
            'schedule' => $attempts,
            'retryTotal' => self::roughDuration($schedule->totalSeconds()),
            'disableDays' => config()->integer('axispay.webhooks.disable_after_failing_days'),
            'previousSecretHours' => config()->integer('axispay.webhooks.previous_secret_hours'),
            'toleranceMinutes' => intdiv(WebhookSigner::TOLERANCE_SECONDS, 60),
            'ports' => self::ports('axispay.webhooks.allowed_ports'),
            'httpPorts' => self::ports('axispay.webhooks.allowed_http_ports'),
            'httpInTest' => (bool) config('axispay.webhooks.allow_http_in_test'),
            'userAgent' => config()->string('axispay.webhooks.user_agent'),
            'events' => array_map(
                static fn (DomainEventType $type): array => ['type' => $type->value, 'description' => WebhookEventType::fromDomain($type)->description()],
                DomainEventType::cases(),
            ),
            'eventId' => PrefixedId::encode(ResourceType::Event, self::EXAMPLE_EVENT_ULID),
            'timestamp' => (string) self::exampleTime()->getTimestamp(),
            'example' => self::pretty(self::succeededExample()),
            'verifyExample' => self::verifyExample(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function validationData(): array
    {
        $callId = PrefixedId::encode(ResourceType::ValidationCall, self::EXAMPLE_CALL_ULID);
        $request = ValidationPayload::sample($callId, false, self::exampleTime());

        return [
            'connectTimeout' => config()->integer('axispay.pre_payment_validation.connect_timeout_seconds'),
            'timeout' => config()->integer('axispay.pre_payment_validation.timeout_seconds'),
            'maxKb' => intdiv(ValidationResponseParser::maxBytes(), 1024),
            'payerMessageMax' => config()->integer('axispay.pre_payment_validation.payer_message_max'),
            'reasonCodeMax' => config()->integer('axispay.pre_payment_validation.reason_code_max'),
            'alertAfter' => config()->integer('axispay.pre_payment_validation.alert_after_failures'),
            'alertIntervalMinutes' => config()->integer('axispay.pre_payment_validation.alert_interval_minutes'),
            'previousSecretHours' => config()->integer('axispay.pre_payment_validation.previous_secret_hours'),
            'retentionDays' => config()->integer('axispay.pre_payment_validation.retention_days'),
            'toleranceMinutes' => intdiv(WebhookSigner::TOLERANCE_SECONDS, 60),
            'ports' => self::ports('axispay.webhooks.allowed_ports'),
            'userAgent' => config()->string('axispay.pre_payment_validation.user_agent'),
            'kindHeader' => PrePaymentValidationClient::KIND_HEADER,
            'kind' => PrePaymentValidationClient::KIND,
            'type' => ValidationPayload::TYPE,
            'callId' => $callId,
            'timestamp' => (string) self::exampleTime()->getTimestamp(),
            'policies' => array_map(
                static fn (ValidationFailurePolicy $policy): array => ['value' => $policy->value, 'label' => $policy->label(), 'explanation' => $policy->explanation()],
                ValidationFailurePolicy::cases(),
            ),
            'request' => self::pretty(ValidationPayload::encode($request)),
            'approveExample' => self::pretty((string) json_encode(['decision' => 'approve'])),
            'rejectExample' => self::pretty((string) json_encode([
                'decision' => 'reject',
                'reason_code' => 'out_of_stock',
                'payer_message' => __('webhooks.help.validation.example_payer_message'),
                'cancel_link' => true,
            ], JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * A `payment.succeeded` envelope, encoded by the same code as the real
     * one. `data.object` holds every field of the payment snapshot; the
     * sibling `payment_link` is shortened (the real one is the whole link
     * object of the API).
     */
    private static function succeededExample(): string
    {
        $time = self::exampleTime();
        $linkId = PrefixedId::encode(ResourceType::PaymentLink, self::EXAMPLE_LINK_ULID);
        $data = [
            'payment' => [
                'id' => PrefixedId::encode(ResourceType::Payment, self::EXAMPLE_PAYMENT_ULID),
                'object' => 'payment',
                'livemode' => false,
                'payment_link' => $linkId,
                'status' => 'succeeded',
                'amount' => '1500.00',
                'amount_minor' => 150000,
                'currency' => 'USD',
                'late_payment' => false,
                'failure_count' => 0,
                'failure' => null,
                'pre_validation' => ['outcome' => 'approved'],
                'created_at' => '2026-09-30T15:03:41Z',
            ],
            'payment_link' => [
                'id' => $linkId,
                'object' => 'payment_link',
                'livemode' => false,
                'status' => 'paid',
                'amount' => '1500.00',
                'amount_minor' => 150000,
                'currency' => 'USD',
                'client_reference_id' => 'ORDER-1029',
                'metadata' => ['order_id' => 'ORDER-1029'],
                'paid_at' => '2026-09-30T15:04:05Z',
            ],
            'late_payment' => false,
        ];

        $object = json_decode((string) json_encode($data), false);
        assert($object instanceof stdClass);

        return WebhookPayload::encode(self::EXAMPLE_EVENT_ULID, WebhookEventType::PaymentSucceeded, false, $time, $object);
    }

    /** How a receiver checks `webhook-signature` (WebhookSigner::verify, as code). */
    private static function verifyExample(): string
    {
        $code = <<<'PHP'
            $key = base64_decode(substr($secret, strlen('whsec_')));
            $signed = $webhookId.'.'.$webhookTimestamp.'.'.$rawBody;
            $expected = 'v1,'.base64_encode(hash_hmac('sha256', $signed, $key, true));

            $valid = abs(time() - (int) $webhookTimestamp) <= 300
                && array_filter(
                    explode(' ', $webhookSignature),
                    fn ($candidate) => hash_equals($expected, $candidate),
                ) !== [];
            PHP;

        return str_replace('<= 300', '<= '.WebhookSigner::TOLERANCE_SECONDS, $code);
    }

    private static function exampleTime(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::EXAMPLE_TIME, 'UTC');
    }

    private static function pretty(string $json): string
    {
        return (string) json_encode(
            json_decode($json, false, 512, JSON_THROW_ON_ERROR),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function duration(int $seconds): string
    {
        return CarbonInterval::seconds($seconds)->cascade()->locale(app()->getLocale())->forHumans(['parts' => 2]);
    }

    /** A long span in whole hours ("27 hours"), shorter ones exactly. */
    private static function roughDuration(int $seconds): string
    {
        return $seconds >= 3600
            ? CarbonInterval::hours(intdiv($seconds, 3600))->locale(app()->getLocale())->forHumans()
            : self::duration($seconds);
    }

    private static function ports(string $key): string
    {
        return implode(', ', array_map(static fn (mixed $port): string => is_scalar($port) ? (string) $port : '', config()->array($key)));
    }
}
