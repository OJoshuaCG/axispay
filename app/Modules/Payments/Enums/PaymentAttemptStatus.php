<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use Filament\Support\Icons\Heroicon;

/**
 * Status of a payment attempt (plan 9.2, ADR-0050). A normalized mirror of the
 * gateway payment, plus `failed`: an attempt closed after at least one
 * decline. `requires_capture` is the "authorized, waiting for capture" stage
 * of the linear flow; it counts as active (one active attempt per link,
 * rules.md rule 9). Transitions only through PaymentAttemptStateMachine.
 */
enum PaymentAttemptStatus: string
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresAction = 'requires_action';
    case RequiresCapture = 'requires_capture';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Canceled], true);
    }

    /**
     * A payment is under way (bank verification, authorized, processing):
     * the link is `processing` and no other attempt may start (ADR-006).
     */
    public function isInFlight(): bool
    {
        return in_array($this, [self::RequiresAction, self::RequiresCapture, self::Processing], true);
    }

    /**
     * The attempt a gateway status corresponds to. A canceled gateway payment
     * closes the attempt as `failed` when it had declines, `canceled`
     * otherwise (plan 9.2).
     */
    public static function fromProvider(ProviderPaymentStatus $status, int $failureCount): self
    {
        return match ($status) {
            ProviderPaymentStatus::RequiresPaymentMethod => self::RequiresPaymentMethod,
            ProviderPaymentStatus::RequiresConfirmation => self::RequiresConfirmation,
            ProviderPaymentStatus::RequiresAction => self::RequiresAction,
            ProviderPaymentStatus::RequiresCapture => self::RequiresCapture,
            ProviderPaymentStatus::Processing => self::Processing,
            ProviderPaymentStatus::Succeeded => self::Succeeded,
            ProviderPaymentStatus::Canceled => $failureCount > 0 ? self::Failed : self::Canceled,
        };
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => ! $status->isTerminal()),
        ));
    }

    public function label(): string
    {
        return __('payments.attempt_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::RequiresCapture, self::Processing, self::RequiresAction => 'warning',
            self::Failed => 'danger',
            self::RequiresPaymentMethod, self::RequiresConfirmation => 'info',
            self::Canceled => 'gray',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Succeeded => Heroicon::OutlinedCheckCircle,
            self::RequiresCapture => Heroicon::OutlinedShieldCheck,
            self::Processing, self::RequiresAction => Heroicon::OutlinedArrowPath,
            self::Failed => Heroicon::OutlinedXCircle,
            self::RequiresPaymentMethod, self::RequiresConfirmation => Heroicon::OutlinedCreditCard,
            self::Canceled => Heroicon::OutlinedNoSymbol,
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
