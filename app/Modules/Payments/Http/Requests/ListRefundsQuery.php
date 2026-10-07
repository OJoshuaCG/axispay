<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Data\ListRefundsData;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use Illuminate\Http\Request;

/**
 * Query string of `GET /v1/refunds` (plan 10.7): `limit` (1–100, default 20),
 * `starting_after` / `ending_before` (refund IDs, not both), `payment` (a
 * payment ID) and `status`. Unknown parameters are ignored.
 */
final class ListRefundsQuery
{
    private function __construct() {}

    public static function from(Request $request): ListRefundsData
    {
        $startingAfter = self::id($request->query('starting_after'), ResourceType::Refund, 'starting_after', 'a refund ID (re_...)');
        $endingBefore = self::id($request->query('ending_before'), ResourceType::Refund, 'ending_before', 'a refund ID (re_...)');

        if ($startingAfter !== null && $endingBefore !== null) {
            throw self::invalid('ending_before', 'Send either starting_after or ending_before, not both.');
        }

        return new ListRefundsData(
            limit: self::limit($request->query('limit')),
            startingAfter: $startingAfter,
            endingBefore: $endingBefore,
            paymentId: self::id($request->query('payment'), ResourceType::Payment, 'payment', 'a payment ID (pay_...)'),
            status: self::status($request->query('status')),
        );
    }

    private static function limit(mixed $value): int
    {
        if ($value === null) {
            return ListRefundsData::DEFAULT_LIMIT;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > ListRefundsData::MAX_LIMIT) {
            throw self::invalid('limit', 'limit must be an integer from 1 to '.ListRefundsData::MAX_LIMIT.'.');
        }

        return (int) $value;
    }

    private static function id(mixed $value, ResourceType $type, string $param, string $expected): ?string
    {
        if ($value === null) {
            return null;
        }

        return PrefixedId::tryParse($value, $type)->ulid ?? throw self::invalid($param, "{$param} must be {$expected}.");
    }

    private static function status(mixed $value): ?RefundState
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? RefundState::tryFrom($value) : null)
            ?? throw self::invalid('status', 'status must be one of: '.implode(', ', RefundState::values()).'.');
    }

    private static function invalid(string $param, string $message): ApiException
    {
        return ApiException::of(ApiErrorCode::ParameterInvalid, $message, $param);
    }
}
