<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Data\ListPaymentsData;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Time\IsoDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Query string of `GET /v1/payments` (plan 10.1, 10.6): `limit` (1–100,
 * default 20), `starting_after` / `ending_before` (payment IDs, not both),
 * `status`, `payment_link` (a link ID), `created[gte]`, `created[lte]` (Unix
 * seconds or ISO-8601 with a time zone). Unknown parameters are ignored.
 */
final class ListPaymentsQuery
{
    private function __construct() {}

    public static function from(Request $request): ListPaymentsData
    {
        $startingAfter = self::cursor($request->query('starting_after'), 'starting_after');
        $endingBefore = self::cursor($request->query('ending_before'), 'ending_before');

        if ($startingAfter !== null && $endingBefore !== null) {
            throw self::invalid('ending_before', 'Send either starting_after or ending_before, not both.');
        }

        $created = $request->query('created');
        $created = is_array($created) ? $created : ($created === null ? [] : throw self::invalid('created', 'created must be used as created[gte] and/or created[lte].'));

        return new ListPaymentsData(
            limit: self::limit($request->query('limit')),
            startingAfter: $startingAfter,
            endingBefore: $endingBefore,
            status: self::status($request->query('status')),
            paymentLinkId: self::link($request->query('payment_link')),
            createdGte: self::time($created['gte'] ?? null, 'created[gte]'),
            createdLte: self::time($created['lte'] ?? null, 'created[lte]'),
        );
    }

    private static function limit(mixed $value): int
    {
        if ($value === null) {
            return ListPaymentsData::DEFAULT_LIMIT;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > ListPaymentsData::MAX_LIMIT) {
            throw self::invalid('limit', 'limit must be an integer from 1 to '.ListPaymentsData::MAX_LIMIT.'.');
        }

        return (int) $value;
    }

    private static function cursor(mixed $value, string $param): ?string
    {
        if ($value === null) {
            return null;
        }

        return PrefixedId::tryParse($value, ResourceType::Payment)->ulid
            ?? throw self::invalid($param, "{$param} must be a payment ID (pay_...).");
    }

    private static function link(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return PrefixedId::tryParse($value, ResourceType::PaymentLink)->ulid
            ?? throw self::invalid('payment_link', 'payment_link must be a payment link ID (plink_...).');
    }

    private static function status(mixed $value): ?PaymentAttemptStatus
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? PaymentAttemptStatus::tryFrom($value) : null)
            ?? throw self::invalid('status', 'status must be one of: '.implode(', ', array_map(static fn (PaymentAttemptStatus $s): string => $s->value, PaymentAttemptStatus::cases())).'.');
    }

    private static function time(mixed $value, string $param): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^[0-9]{1,11}$/D', $value) === 1) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        return IsoDateTime::parse($value) ?? throw self::invalid($param, "{$param} must be a Unix timestamp or an ISO-8601 date-time with a time zone.");
    }

    private static function invalid(string $param, string $message): ApiException
    {
        return ApiException::of(ApiErrorCode::ParameterInvalid, $message, $param);
    }
}
