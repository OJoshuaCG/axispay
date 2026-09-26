<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Expired rows of the database cache store are purged hourly (keys that are
 * never read again, such as per-IP failed-authentication counters).
 */
it('deletes expired cache values and locks, in chunks, and keeps live ones', function (): void {
    config(['cache.default' => 'database']);
    $now = now()->getTimestamp();

    $rows = [];

    foreach (range(1, 1005) as $i) {
        $rows[] = ['key' => "expired-{$i}", 'value' => 'x', 'expiration' => $now - 10];
    }

    DB::table('cache')->insert($rows);
    DB::table('cache')->insert(['key' => 'live', 'value' => 'x', 'expiration' => $now + 3600]);
    DB::table('cache_locks')->insert([
        ['key' => 'old-lock', 'owner' => 'o', 'expiration' => $now - 1],
        ['key' => 'held-lock', 'owner' => 'o', 'expiration' => $now + 60],
    ]);

    artisanCommand('axispay:cache:purge-expired')
        ->expectsOutputToContain('Deleted 1006 expired cache row(s).')
        ->assertSuccessful();

    expect(DB::table('cache')->pluck('key')->all())->toBe(['live'])
        ->and(DB::table('cache_locks')->pluck('key')->all())->toBe(['held-lock']);
});

it('does nothing when the cache store is not the database', function (): void {
    config(['cache.default' => 'array']);
    DB::table('cache')->insert(['key' => 'expired', 'value' => 'x', 'expiration' => now()->getTimestamp() - 10]);

    artisanCommand('axispay:cache:purge-expired')->expectsOutputToContain('nothing to purge')->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(1);
});

it('is scheduled hourly without overlap on one server', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'axispay:cache:purge-expired'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});
