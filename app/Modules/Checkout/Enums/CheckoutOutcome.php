<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Enums;

/**
 * What the payment page is told after a payment request (plan 11.4, 11.5).
 * The page shows the matching copy (docs/frontend/checkout-design.md states); the detailed reason
 * of a decline stays in the tenant panel (plan 11.7 rule 7).
 */
enum CheckoutOutcome: string
{
    case Paid = 'paid';
    case Processing = 'processing';
    case RequiresAction = 'requires_action';
    case Declined = 'declined';
    case AuthenticationFailed = 'authentication_failed';
    case MerchantRejected = 'merchant_rejected';
    case TurnstileRequired = 'turnstile_required';
    case RateLimited = 'rate_limited';
    case Blocked = 'blocked';
    case InProgress = 'in_progress';
    case AlreadyPaid = 'already_paid';
    case Expired = 'expired';
    case Canceled = 'canceled';
    case Unavailable = 'unavailable';
    case Error = 'error';

    /** The page leaves the form for the completion page. */
    public function leavesForm(): bool
    {
        return in_array($this, [self::Paid, self::Processing, self::InProgress, self::AlreadyPaid, self::Expired, self::Canceled], true);
    }

    /** HTTP status of the JSON answer (a refusal is still a handled answer). */
    public function httpStatus(): int
    {
        return match ($this) {
            self::RateLimited => 429,
            self::Error, self::Unavailable => 503,
            self::Expired, self::Canceled, self::AlreadyPaid, self::InProgress, self::Blocked => 409,
            self::Declined, self::AuthenticationFailed, self::TurnstileRequired, self::MerchantRejected => 402,
            default => 200,
        };
    }
}
