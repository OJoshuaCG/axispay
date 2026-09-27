<?php

declare(strict_types=1);

use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Shared\Ids\Ulid;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Migration 2026_09_28_000200 (ADR-0051) over a table that already holds
 * events: rows written in the old schema (plain JSON) come out encrypted,
 * with the reduced flag set from their content, and down() restores them.
 *
 * No RefreshDatabase: the migration runs DDL (implicit commits). The
 * database is rebuilt afterwards.
 */
beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->toEndWith('_testing');
    Artisan::call('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/** Runs `up` or `down` of the payload encryption migration. */
function payloadMigration(string $direction): void
{
    $migration = require database_path('migrations/2026_09_28_000200_encrypt_provider_event_payloads.php');
    $callable = [$migration, $direction];

    is_callable($callable) ? $callable() : throw new LogicException('Not a migration.');
}

it('encrypts existing payloads, flags the reduced ones and reverts', function (): void {
    payloadMigration('down'); // back to the old schema: plain JSON, no flag

    $full = '{"id":"evt_Full0001","type":"account.updated","data":{"object":{"id":"acct_1","email":"owner@example.com"}}}';
    $reduced = '{"id":"evt_Red0001","type":"payment_intent.succeeded","axispay_reduced":true}';
    [$fullId, $reducedId] = [Ulid::generate(), Ulid::generate()];

    // Query-builder inserts bypass the model casts (the old rows are plain JSON).
    foreach ([[$fullId, 'evt_Full0001', $full], [$reducedId, 'evt_Red0001', $reduced]] as [$id, $eventId, $payload]) {
        ProviderEvent::query()->withoutGlobalScopes()->insert([
            'id' => $id, 'provider' => 'stripe', 'provider_event_id' => $eventId, 'livemode' => false, 'type' => 'x',
            'payload' => $payload, 'status' => 'unroutable', 'attempts' => 0, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    payloadMigration('up');

    $fullRow = ProviderEvent::query()->withoutGlobalScopes()->findOrFail($fullId);
    $reducedRow = ProviderEvent::query()->withoutGlobalScopes()->findOrFail($reducedId);
    $rawFull = $fullRow->getRawOriginal('payload');

    expect($fullRow->payload)->toBe($full)
        ->and($reducedRow->payload)->toBe($reduced)
        ->and($fullRow->payload_reduced)->toBeFalse()
        ->and($reducedRow->payload_reduced)->toBeTrue()
        ->and(is_string($rawFull) ? $rawFull : '')->not->toContain('owner@example.com')
        ->and(Crypt::decryptString(is_string($rawFull) ? $rawFull : ''))->toBe($full);

    payloadMigration('down');

    $plain = ProviderEvent::query()->withoutGlobalScopes()->whereKey($fullId)->toBase()->value('payload');
    expect($plain)->toBe($full);

    payloadMigration('up'); // leave the schema as the application expects
});
