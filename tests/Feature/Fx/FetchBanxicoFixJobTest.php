<?php

declare(strict_types=1);

use App\Modules\Fx\Jobs\FetchBanxicoFixJob;
use App\Modules\Fx\Models\StoredExchangeRate;
use App\Modules\Fx\Notifications\FxRateAlertNotification;
use App\Modules\Fx\Services\FxRates;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\travelTo;

/**
 * FetchBanxicoFixJob (plan 13.3, ADR-0063): the FIX of the Banxico SIE API
 * is fetched on a schedule, stored once per publication date, sanity-checked
 * and never fetched without the token. The tests use HTTP fakes: Banxico is
 * never called for real.
 */
const BANXICO_URL = 'https://www.banxico.org.mx/SieAPIRest/service/v1/series/SF43718/datos/oportuno';

/**
 * @return array<string, mixed>
 */
function banxicoBody(string $date, string $value): array
{
    return ['bmx' => ['series' => [[
        'idSerie' => 'SF43718',
        'titulo' => 'Tipo de cambio pesos por dolar E.U.A. FIX',
        'datos' => [['fecha' => $date, 'dato' => $value]],
    ]]]];
}

function runFetch(): void
{
    app()->call([new FetchBanxicoFixJob, 'handle']);
}

beforeEach(function (): void {
    travelTo('2026-10-07 19:00:00');
    config(['axispay.fx.banxico.token' => 'banxico-test-token']);
    Notification::fake();
});

it('stores the FIX of the day with its raw payload and uses it as the usable rate', function (): void {
    Http::fake([BANXICO_URL => Http::response(banxicoBody('07/10/2026', '17.4225'))]);

    runFetch();

    $row = StoredExchangeRate::query()->sole();

    expect($row->source)->toBe('banxico_fix')
        ->and($row->base_currency)->toBe('USD')
        ->and($row->quote_currency)->toBe('MXN')
        ->and($row->rate)->toBe('17.422500')
        ->and($row->rate_date)->toBe('2026-10-07')
        ->and($row->requires_review)->toBeFalse()
        ->and($row->raw_payload)->toBe(banxicoBody('07/10/2026', '17.4225'))
        ->and(app(FxRates::class)->usableFix()?->id)->toBe($row->id);

    Http::assertSent(static fn (Request $request): bool => $request->url() === BANXICO_URL
        && $request->hasHeader('Bmx-Token', 'banxico-test-token')
        && $request->hasHeader('Accept', 'application/json'));
    Notification::assertNothingSent();
});

it('is idempotent: a FIX already stored for its date is left as it is', function (): void {
    Http::fake([BANXICO_URL => Http::sequence()
        ->push(banxicoBody('07/10/2026', '17.4225'))
        ->push(banxicoBody('07/10/2026', '17.9999'))]);

    runFetch();
    runFetch();

    expect(StoredExchangeRate::query()->count())->toBe(1)
        ->and(StoredExchangeRate::query()->sole()->rate)->toBe('17.422500');
});

it('never calls Banxico without the token', function (): void {
    config(['axispay.fx.banxico.token' => null]);
    Http::fake();

    runFetch();

    Http::assertNothingSent();
    expect(StoredExchangeRate::query()->count())->toBe(0);
});

it('stores nothing when Banxico has no figure (N/E) or an unreadable answer', function (array $body): void {
    Http::fake([BANXICO_URL => Http::response($body)]);

    runFetch();

    expect(StoredExchangeRate::query()->count())->toBe(0);
})->with([
    'not available' => [banxicoBody('07/10/2026', 'N/E')],
    'bad date' => [banxicoBody('2026-10-07', '17.42')],
    'no series' => [['bmx' => ['series' => []]]],
    'unexpected shape' => [['error' => 'x']],
    'zero' => [banxicoBody('07/10/2026', '0')],
]);

it('fails the job (to be retried) when Banxico answers with an error', function (): void {
    Http::fake([BANXICO_URL => Http::response('boom', 503)]);

    expect(fn () => runFetch())->toThrow(RequestException::class);
    expect(StoredExchangeRate::query()->count())->toBe(0);
});

it('flags a FIX that moved more than 10 % from the previous one for review, never uses it, and alerts the superadmins', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $previous = new StoredExchangeRate;
    $previous->forceFill(['source' => 'banxico_fix', 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '17.000000', 'rate_date' => '2026-10-06', 'fetched_at' => now(), 'requires_review' => false])->save();
    Http::fake([BANXICO_URL => Http::response(banxicoBody('07/10/2026', '19.1000'))]);

    runFetch();

    $row = StoredExchangeRate::query()->where('rate_date', '2026-10-07')->sole();

    expect($row->requires_review)->toBeTrue()
        ->and(app(FxRates::class)->usableFix()?->id)->toBe($previous->id);
    Notification::assertSentTo($admin, FxRateAlertNotification::class, static fn (FxRateAlertNotification $n): bool => $n->kind === FxRateAlertNotification::REQUIRES_REVIEW);
});

it('accepts a FIX at exactly 10 % from the previous one', function (): void {
    $previous = new StoredExchangeRate;
    $previous->forceFill(['source' => 'banxico_fix', 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '17.000000', 'rate_date' => '2026-10-06', 'fetched_at' => now(), 'requires_review' => false])->save();
    Http::fake([BANXICO_URL => Http::response(banxicoBody('07/10/2026', '18.7000'))]);

    runFetch();

    expect(StoredExchangeRate::query()->where('rate_date', '2026-10-07')->sole()->requires_review)->toBeFalse();
});

it('alerts the superadmins, once a day, when the newest usable FIX is stale', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $old = new StoredExchangeRate;
    $old->forceFill(['source' => 'banxico_fix', 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '17.000000', 'rate_date' => '2026-10-01', 'fetched_at' => now(), 'requires_review' => false])->save();
    Http::fake([BANXICO_URL => Http::response(banxicoBody('01/10/2026', '17.0000'))]);

    runFetch();
    runFetch();

    Notification::assertSentToTimes($admin, FxRateAlertNotification::class, 1);
    Notification::assertSentTo($admin, FxRateAlertNotification::class, static fn (FxRateAlertNotification $n): bool => $n->kind === FxRateAlertNotification::STALE);
});

it('is scheduled on weekdays at 12:30, 13:30 and 17:00 Mexico City time with a 09:00 fallback the next day', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(static fn (Event $event): bool => str_contains((string) $event->description, FetchBanxicoFixJob::class) || str_contains($event->command ?? '', 'banxico'))
        ->values();

    expect($events->map(static fn (Event $event): string => $event->expression)->sort()->values()->all())->toBe(['0 17 * * 1-5', '0 9 * * 2-6', '30 12,13 * * 1-5'])
        ->and($events->map(static fn (Event $event): string => (is_string($event->timezone) ? $event->timezone : ($event->timezone?->getName() ?? '')))->unique()->all())->toBe(['America/Mexico_City'])
        ->and($events->every(static fn (Event $event): bool => $event->onOneServer && $event->withoutOverlapping))->toBeTrue();
});
