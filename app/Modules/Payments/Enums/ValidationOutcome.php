<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * The merchant's decision on an authorized attempt (ADR-0050 step 4), kept on
 * the attempt the moment it is known, so it is never asked twice and a
 * rejected authorization is never captured by a later retry, webhook or
 * reconciliation. A failed call (timeout, error, invalid answer; plan
 * 15.8.5) is kept with the policy it applied: `failed_open` captures,
 * `failed_closed` voids.
 */
enum ValidationOutcome: string
{
    case NotConfigured = 'not_configured';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case FailedOpen = 'failed_open';
    case FailedClosed = 'failed_closed';

    public function allowsCapture(): bool
    {
        return ! in_array($this, [self::Rejected, self::FailedClosed], true);
    }

    /**
     * The `pre_validation` block of the payment in the API and the webhooks
     * (plan 15.8.5, 27 Phase 5); null when no validation applied.
     *
     * @return array{outcome: string, policy_applied?: string}|null
     */
    public function publicBlock(): ?array
    {
        return match ($this) {
            self::NotConfigured => null,
            self::Approved => ['outcome' => 'approved'],
            self::Rejected => ['outcome' => 'rejected'],
            self::FailedOpen => ['outcome' => 'failed', 'policy_applied' => 'fail_open'],
            self::FailedClosed => ['outcome' => 'failed', 'policy_applied' => 'fail_closed'],
        };
    }

    public function label(): string
    {
        return __('payments.validation_outcome.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::NotConfigured => 'gray',
            self::Approved => 'success',
            self::Rejected => 'warning',
            self::FailedOpen, self::FailedClosed => 'danger',
        };
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
