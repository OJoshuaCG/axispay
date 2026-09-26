<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Plan 12.3.3: daily health check of api_key gateway connections (one queued
// job per connection). Every scheduled task uses withoutOverlapping() and
// onOneServer() (docs/development.md, ADR-0039).
Schedule::command('axispay:gateways:check-api-keys')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

// Plan 14.4: retention of incoming gateway events (ADR-0047).
Schedule::command('axispay:provider-events:purge')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// Plan 7.8: idempotency records live 24 hours (ADR-0048).
Schedule::command('axispay:idempotency:purge')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Expired rows of the database cache store (per-IP failed-authentication
// counters, locks) are otherwise only removed when read again.
Schedule::command('axispay:cache:purge-expired')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->when(static fn (): bool => config('cache.stores.'.config()->string('cache.default').'.driver') === 'database');
