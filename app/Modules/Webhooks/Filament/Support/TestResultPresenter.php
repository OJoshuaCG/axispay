<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Filament\Support;

use App\Modules\Webhooks\Data\ValidationTestResult;
use App\Modules\Webhooks\Enums\WebhookDeliveryStatus;
use App\Modules\Webhooks\Models\WebhookDelivery;
use Filament\Support\Icons\Heroicon;

/**
 * Turns the result of "Send test event" (plan 15.1) and of "Test
 * validation" (plan 15.8.1) into plain, translated values for the result
 * dialog (view `filament.webhooks.test-result`). Presentation only: the
 * results come from SendTestWebhook and TestValidationEndpoint. Only
 * scalars, so the page can keep them between requests.
 */
final class TestResultPresenter
{
    private function __construct() {}

    /**
     * @return array{outcome: string, color: string, icon: string, next_step: ?string, rows: list<array{label: string, value: string, mono: bool}>, errors: list<string>, warnings: list<string>, excerpt: ?string}
     */
    public static function delivery(WebhookDelivery $delivery): array
    {
        $succeeded = $delivery->status === WebhookDeliveryStatus::Succeeded;
        $rows = [
            self::row(__('webhooks.test_result.http_status'), $delivery->response_status !== null ? (string) $delivery->response_status : __('webhooks.test_result.no_answer'), true),
            self::row(__('webhooks.test_result.latency'), self::latency($delivery->duration_ms), true),
        ];

        if ($delivery->error !== null) {
            $rows[] = self::row(__('webhooks.test_result.error'), $delivery->error->label());
        }

        return [
            'outcome' => $succeeded ? __('webhooks.test_result.delivered') : __('webhooks.test_result.not_delivered'),
            'color' => $succeeded ? 'success' : 'danger',
            'icon' => self::icon($succeeded ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedXCircle),
            // What to do about it, in one sentence per error.
            'next_step' => $delivery->error?->nextStep(),
            'rows' => $rows,
            'errors' => [],
            'warnings' => [],
            'excerpt' => $delivery->response_body_excerpt,
        ];
    }

    /**
     * @return array{outcome: string, color: string, icon: string, next_step: ?string, rows: list<array{label: string, value: string, mono: bool}>, errors: list<string>, warnings: list<string>, excerpt: ?string}
     */
    public static function validation(ValidationTestResult $result): array
    {
        $rows = [
            self::row(__('webhooks.test_result.http_status'), $result->httpStatus !== null ? (string) $result->httpStatus : __('webhooks.test_result.no_answer'), true),
            self::row(__('webhooks.test_result.latency'), self::latency($result->latencyMs), true),
            self::row(__('webhooks.test_result.decision'), match ($result->decision) {
                'approve' => __('webhooks.test_result.decision_approve'),
                'reject' => __('webhooks.test_result.decision_reject'),
                default => __('webhooks.test_result.decision_none'),
            }),
        ];

        if ($result->failureKind !== null) {
            $rows[] = self::row(__('webhooks.test_result.error'), $result->failureKind->label());
        }

        $errors = [];
        $warnings = [];

        foreach ($result->errors as $problem) {
            $errors[] = $problem->message();
        }

        foreach ($result->warnings as $problem) {
            $warnings[] = $problem->message();
        }

        // The badge states the decision the answer carries (or that it has none usable).
        $approved = $result->formatValid && $result->decision === 'approve';
        $rejected = $result->formatValid && $result->decision === 'reject';

        return [
            'outcome' => match (true) {
                $approved => __('webhooks.test_result.validation_approved'),
                $rejected => __('webhooks.test_result.validation_rejected'),
                default => __('webhooks.test_result.validation_invalid'),
            },
            'color' => match (true) {
                ! $approved && ! $rejected => 'danger',
                $warnings !== [] => 'warning',
                $approved => 'success',
                default => 'info',
            },
            'icon' => self::icon(match (true) {
                $approved => Heroicon::OutlinedCheckCircle,
                $rejected => Heroicon::OutlinedHandRaised,
                default => Heroicon::OutlinedXCircle,
            }),
            'next_step' => null,
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => $warnings,
            'excerpt' => $result->responseExcerpt,
        ];
    }

    /** `245 ms`, or a dash when nothing was measured. */
    public static function latency(?int $milliseconds): string
    {
        return $milliseconds !== null ? __('webhooks.test_result.latency_value', ['ms' => number_format($milliseconds)]) : '—';
    }

    /**
     * @return array{label: string, value: string, mono: bool}
     */
    private static function row(string $label, string $value, bool $mono = false): array
    {
        return ['label' => $label, 'value' => $value, 'mono' => $mono];
    }

    private static function icon(Heroicon $icon): string
    {
        return 'heroicon-'.$icon->value;
    }
}
