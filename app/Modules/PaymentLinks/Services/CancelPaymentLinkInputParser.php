<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Services;

use App\Modules\PaymentLinks\Data\CancelPaymentLinkData;
use App\Modules\PaymentLinks\Exceptions\PaymentLinkRejectedException;
use App\Modules\Shared\Http\Errors\ApiErrorCode;

/**
 * Rules of the cancel request (plan 10.5), shared by the API and the panel:
 * only `reason`, a string of up to 500 characters (spaces trimmed; empty
 * means no reason).
 */
final class CancelPaymentLinkInputParser
{
    public const int REASON_MAX = 500;

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws PaymentLinkRejectedException
     */
    public function parse(array $input): CancelPaymentLinkData
    {
        foreach (array_keys($input) as $field) {
            if ($field !== 'reason') {
                throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, "Received unknown parameter: {$field}.", (string) $field);
            }
        }

        $reason = $input['reason'] ?? null;

        if ($reason !== null && (! is_string($reason) || mb_strlen(trim($reason)) > self::REASON_MAX)) {
            throw PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, 'reason must be a string of at most '.self::REASON_MAX.' characters.', 'reason');
        }

        $reason = is_string($reason) ? trim($reason) : null;

        return new CancelPaymentLinkData($reason === '' ? null : $reason);
    }
}
