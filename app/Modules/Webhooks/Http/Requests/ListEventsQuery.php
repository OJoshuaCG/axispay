<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Requests;

use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Time\IsoDateTime;
use App\Modules\Webhooks\Data\ListEventsData;
use App\Modules\Webhooks\Enums\WebhookEventType;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Query string of `GET /v1/events` (plan 10.1, 10.8): `limit` (1–100,
 * default 20), `starting_after` / `ending_before` (event IDs, not both),
 * `type` (one event type of the catalog), `created[gte]`, `created[lte]`
 * (Unix seconds or ISO-8601 with a time zone). Unknown parameters are
 * ignored.
 */
final class ListEventsQuery
{
    private function __construct() {}

    public static function from(Request $request): ListEventsData
    {
        $startingAfter = self::cursor($request->query('starting_after'), 'starting_after');
        $endingBefore = self::cursor($request->query('ending_before'), 'ending_before');

        if ($startingAfter !== null && $endingBefore !== null) {
            throw self::invalid('ending_before', 'Send either starting_after or ending_before, not both.');
        }

        $created = $request->query('created');
        $created = is_array($created) ? $created : ($created === null ? [] : throw self::invalid('created', 'created must be used as created[gte] and/or created[lte].'));

        return new ListEventsData(
            limit: self::limit($request->query('limit')),
            startingAfter: $startingAfter,
            endingBefore: $endingBefore,
            type: self::type($request->query('type')),
            createdGte: self::time($created['gte'] ?? null, 'created[gte]'),
            createdLte: self::time($created['lte'] ?? null, 'created[lte]'),
        );
    }

    private static function limit(mixed $value): int
    {
        if ($value === null) {
            return ListEventsData::DEFAULT_LIMIT;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > ListEventsData::MAX_LIMIT) {
            throw self::invalid('limit', 'limit must be an integer from 1 to '.ListEventsData::MAX_LIMIT.'.');
        }

        return (int) $value;
    }

    private static function cursor(mixed $value, string $param): ?string
    {
        if ($value === null) {
            return null;
        }

        return PrefixedId::tryParse($value, ResourceType::Event)->ulid
            ?? throw self::invalid($param, "{$param} must be an event ID (evt_...).");
    }

    private static function type(mixed $value): ?WebhookEventType
    {
        if ($value === null) {
            return null;
        }

        $type = is_string($value) ? WebhookEventType::tryFrom($value) : null;

        return $type !== null && $type->isSubscribable()
            ? $type
            : throw self::invalid('type', 'type must be one of: '.implode(', ', WebhookEventType::subscribableValues()).'.');
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
