<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Data\CreateRefundData;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Money\AmountParser;
use App\Modules\Shared\Money\Money;

/**
 * Rules of the refund request (plan 10.7): `payment` (a `pay_...` ID, required),
 * `amount` (a decimal string in the currency charged; omitted means
 * everything that is still refundable) and `reason` (optional, `other` when
 * missing). Anything else is refused. A payment of another tenant or mode, or
 * an unknown one, is a 404 like everywhere else (plan 6.6).
 */
final readonly class RefundInputParser
{
    private const array FIELDS = ['payment', 'amount', 'reason'];

    public function __construct(private AmountParser $amounts) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ApiException
     */
    public function parse(array $input): CreateRefundData
    {
        foreach (array_keys($input) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                throw ApiException::of(ApiErrorCode::ParameterInvalid, "Received unknown parameter: {$field}.", (string) $field);
            }
        }

        $paymentId = $input['payment'] ?? null;

        if ($paymentId === null || $paymentId === '') {
            throw ApiException::of(ApiErrorCode::ParameterMissing, 'Missing required parameter: payment.', 'payment');
        }

        $parsed = PrefixedId::tryParse($paymentId, ResourceType::Payment)
            ?? throw ApiException::of(ApiErrorCode::ParameterInvalid, 'payment must be a payment ID (pay_...).', 'payment');

        $attempt = PaymentAttempt::query()->find($parsed->ulid)
            ?? throw ApiException::of(ApiErrorCode::ResourceNotFound, 'No such payment.');

        return new CreateRefundData($attempt->id, $this->amount($input['amount'] ?? null, $attempt), $this->reason($input['reason'] ?? null));
    }

    private function amount(mixed $amount, PaymentAttempt $attempt): ?Money
    {
        if ($amount === null) {
            return null;
        }

        // In the currency charged (MXN after a conversion): the payer's statement shows it.
        return $this->amounts->parsePart($amount, $attempt->currency, 'amount');
    }

    private function reason(mixed $reason): RefundReason
    {
        if ($reason === null) {
            return RefundReason::Other;
        }

        return (is_string($reason) ? RefundReason::tryFrom($reason) : null)
            ?? throw ApiException::of(ApiErrorCode::ParameterInvalid, 'reason must be one of: '.implode(', ', RefundReason::values()).'.', 'reason');
    }
}
