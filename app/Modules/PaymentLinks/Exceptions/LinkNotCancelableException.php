<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Exceptions;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;

/**
 * The link's state does not allow canceling it (plan 9.1, 10.5).
 */
final class LinkNotCancelableException extends ApiException
{
    public static function inState(PaymentLinkStatus $status): self
    {
        return $status === PaymentLinkStatus::Processing
            ? self::of(ApiErrorCode::LinkPaymentInProgress, 'A payment is in progress for this payment link; it cannot be canceled now.')
            : self::of(ApiErrorCode::LinkNotCancelable, "The payment link is {$status->value} and can no longer be canceled.");
    }
}
