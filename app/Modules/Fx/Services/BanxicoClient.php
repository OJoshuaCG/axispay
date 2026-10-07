<?php

declare(strict_types=1);

namespace App\Modules\Fx\Services;

use App\Modules\Fx\Data\BanxicoFix;
use App\Modules\Shared\Money\ExchangeRate;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Reads the USD to MXN FIX from the Banxico SIE API (plan 13.3): the latest
 * published value of the series (`datos/oportuno`), with the token in the
 * `Bmx-Token` header and a short timeout. Only FetchBanxicoFixJob uses it;
 * the checkout never calls Banxico.
 *
 * Banxico answers dates as `dd/mm/yyyy` and the figure as a string, which is
 * `N/E` when there is none: both are validated strictly and anything else is
 * treated as "no figure" (null), never guessed.
 */
final class BanxicoClient
{
    private const string DATE_PATTERN = '#^(\d{2})/(\d{2})/(\d{4})$#D';

    public function configured(): bool
    {
        $token = config('axispay.fx.banxico.token');

        return is_string($token) && $token !== '';
    }

    /**
     * @throws RequestException when Banxico answers with an error status or cannot be reached
     */
    public function fetchLatest(): ?BanxicoFix
    {
        $base = rtrim(config()->string('axispay.fx.banxico.base_url'), '/');
        $series = config()->string('axispay.fx.banxico.series_id');

        $response = Http::withHeaders(['Bmx-Token' => config()->string('axispay.fx.banxico.token'), 'Accept' => 'application/json'])
            ->timeout(config()->integer('axispay.fx.banxico.timeout_seconds'))
            ->get("{$base}/series/{$series}/datos/oportuno")
            ->throw();

        $payload = $response->json();

        return is_array($payload) ? self::parse($payload) : null;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function parse(array $payload): ?BanxicoFix
    {
        $series = data_get($payload, 'bmx.series.0');
        $points = is_array($series) ? ($series['datos'] ?? null) : null;
        $latest = is_array($points) ? end($points) : false;

        if (! is_array($latest)) {
            return null;
        }

        $date = self::date($latest['fecha'] ?? null);
        $rate = ExchangeRate::tryOf($latest['dato'] ?? null);

        return $date !== null && $rate !== null ? new BanxicoFix($date, $rate, $payload) : null;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::DATE_PATTERN, $value, $match) !== 1) {
            return null;
        }

        [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
}
