<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Http\Requests;

use App\Modules\PaymentLinks\Data\ListPaymentLinksData;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Exceptions\PaymentLinkRejectedException;
use App\Modules\PaymentLinks\Services\PaymentLinkInputParser;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Time\IsoDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Query string of `GET /v1/payment_links` (plan 10.1, 10.5): `limit`
 * (1–100, default 20), `starting_after` / `ending_before` (link IDs, not
 * both), `status`, `currency`, `client_reference_id`, `created[gte]`,
 * `created[lte]` (Unix seconds or ISO-8601 with a time zone). Unknown
 * parameters are ignored.
 */
final class ListPaymentLinksQuery
{
    private function __construct() {}

    public static function from(Request $request): ListPaymentLinksData
    {
        $startingAfter = self::cursor($request->query('starting_after'), 'starting_after');
        $endingBefore = self::cursor($request->query('ending_before'), 'ending_before');

        if ($startingAfter !== null && $endingBefore !== null) {
            throw self::invalid('ending_before', 'Send either starting_after or ending_before, not both.');
        }

        $created = $request->query('created');
        $created = is_array($created) ? $created : ($created === null ? [] : throw self::invalid('created', 'created must be used as created[gte] and/or created[lte].'));

        return new ListPaymentLinksData(
            limit: self::limit($request->query('limit')),
            startingAfter: $startingAfter,
            endingBefore: $endingBefore,
            status: self::status($request->query('status')),
            currency: self::currency($request->query('currency')),
            clientReferenceId: self::reference($request->query('client_reference_id')),
            createdGte: self::time($created['gte'] ?? null, 'created[gte]'),
            createdLte: self::time($created['lte'] ?? null, 'created[lte]'),
        );
    }

    private static function limit(mixed $value): int
    {
        if ($value === null) {
            return ListPaymentLinksData::DEFAULT_LIMIT;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > ListPaymentLinksData::MAX_LIMIT) {
            throw self::invalid('limit', 'limit must be an integer from 1 to '.ListPaymentLinksData::MAX_LIMIT.'.');
        }

        return (int) $value;
    }

    private static function cursor(mixed $value, string $param): ?string
    {
        if ($value === null) {
            return null;
        }

        return PrefixedId::tryParse($value, ResourceType::PaymentLink)->ulid
            ?? throw self::invalid($param, "{$param} must be a payment link ID (plink_...).");
    }

    private static function status(mixed $value): ?PaymentLinkStatus
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? PaymentLinkStatus::tryFrom($value) : null)
            ?? throw self::invalid('status', 'status must be one of: '.implode(', ', array_map(static fn (PaymentLinkStatus $s): string => $s->value, PaymentLinkStatus::cases())).'.');
    }

    private static function currency(mixed $value): ?CurrencyCode
    {
        if ($value === null) {
            return null;
        }

        return CurrencyCode::tryFromInput($value)
            ?? throw self::invalid('currency', 'currency must be one of: '.implode(', ', array_keys(CurrencyCode::options())).'.');
    }

    private static function reference(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '' || mb_strlen($value) > PaymentLinkInputParser::CLIENT_REFERENCE_MAX) {
            throw self::invalid('client_reference_id', 'client_reference_id must be a string of 1 to '.PaymentLinkInputParser::CLIENT_REFERENCE_MAX.' characters.');
        }

        return $value;
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

    private static function invalid(string $param, string $message): PaymentLinkRejectedException
    {
        return PaymentLinkRejectedException::of(ApiErrorCode::ParameterInvalid, $message, $param);
    }
}
