<?php

declare(strict_types=1);

namespace App\Modules\Shared\Time;

use Carbon\CarbonImmutable;

/**
 * Strict ISO-8601 date-times of the API (plan 10.1): `YYYY-MM-DDTHH:MM`,
 * optional seconds and up to six decimals, and a mandatory zone (`Z` or
 * `±HH:MM`). Impossible values (February 31, hour 24, offset +15:00) are
 * refused instead of rolled over. Returns the instant in UTC, or null.
 */
final class IsoDateTime
{
    private const string PATTERN = '/^(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})T(?<h>\d{2}):(?<i>\d{2})(?::(?<s>\d{2})(?:\.(?<f>\d{1,6}))?)?(?<zone>Z|(?<sign>[+\-])(?<oh>\d{2}):(?<om>\d{2}))$/D';

    private function __construct() {}

    /** The API's output form: UTC, seconds, `Z` (`2026-09-23T18:30:00Z`, plan 10.1). */
    public static function format(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $part = static fn (string $name): string => is_string($m[$name] ?? null) ? $m[$name] : '';
        [$year, $month, $day, $hour, $minute, $second] = [(int) $part('y'), (int) $part('m'), (int) $part('d'), (int) $part('h'), (int) $part('i'), (int) $part('s')];

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $utc = $part('zone') === 'Z';
        [$offsetHours, $offsetMinutes] = [(int) $part('oh'), (int) $part('om')];

        if (! $utc && ($offsetMinutes > 59 || $offsetHours > 14 || ($offsetHours === 14 && $offsetMinutes > 0))) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s.uP', sprintf(
            '%04d-%02d-%02dT%02d:%02d:%02d.%s%s',
            $year, $month, $day, $hour, $minute, $second,
            str_pad($part('f'), 6, '0'),
            $utc ? '+00:00' : $part('sign').$part('oh').':'.$part('om'),
        ));

        return $parsed instanceof CarbonImmutable ? $parsed->utc() : null;
    }
}
