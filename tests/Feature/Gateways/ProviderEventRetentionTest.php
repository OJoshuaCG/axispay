<?php

declare(strict_types=1);

use App\Modules\Gateways\Enums\GatewayProvider;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\ProviderEvents\Enums\ProviderEventStatus;
use App\Modules\ProviderEvents\Models\ProviderEvent;
use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\StripeFixtures;

/**
 * Plan 14.4 / ADR-0047: ignored and unroutable events are deleted after 7
 * days; processed ones keep only the reduced payload after 30 days.
 */
function storeEvent(?GatewayConnection $connection, ProviderEventStatus $status, int $daysAgo): ProviderEvent
{
    $payload = (string) json_encode(StripeFixtures::load('account.updated', ['event' => 'evt_'.Ulid::generate(), 'account' => 'acct_Retention01', 'livemode' => false]));
    $attributes = [
        'provider' => GatewayProvider::Stripe,
        'provider_event_id' => 'evt_'.Ulid::generate(),
        'provider_account_id' => 'acct_Retention01',
        'livemode' => false,
        'type' => 'account.updated',
        'payload' => $payload,
        'status' => $status,
        'received_at' => now()->subDays($daysAgo),
    ];

    $store = static function () use ($attributes, $connection): ProviderEvent {
        $row = new ProviderEvent;
        $row->forceFill($connection === null ? [...$attributes, 'tenant_id' => null] : [...$attributes, 'gateway_connection_id' => $connection->id])->save();

        return $row;
    };

    return $connection === null ? $store() : app(TenantContext::class)->runAsTenant($connection->tenant_id, false, $store);
}

function eventRow(string $id): ?ProviderEvent
{
    return ProviderEvent::query()->withoutGlobalScopes()->find($id);
}

it('deletes old ignored and unroutable events and reduces old processed payloads', function (): void {
    $connection = GatewayTestHelpers::connection(activeTenant());
    $oldIgnored = storeEvent($connection, ProviderEventStatus::Ignored, 8);
    $oldUnroutable = storeEvent(null, ProviderEventStatus::Unroutable, 8);
    $recentIgnored = storeEvent($connection, ProviderEventStatus::Ignored, 2);
    $oldProcessed = storeEvent($connection, ProviderEventStatus::Processed, 31);
    $recentProcessed = storeEvent($connection, ProviderEventStatus::Processed, 5);

    artisanCommand('axispay:provider-events:purge')->assertSuccessful();

    $reduced = eventRow($oldProcessed->id);

    expect(eventRow($oldIgnored->id))->toBeNull()
        ->and(eventRow($oldUnroutable->id))->toBeNull()
        ->and(eventRow($recentIgnored->id))->not->toBeNull()
        ->and($reduced?->tenant_id)->toBe($connection->tenant_id)
        ->and(jsonArray((string) $reduced?->payload)['axispay_reduced'] ?? null)->toBeTrue()
        ->and((string) $reduced?->payload)->not->toContain('requirements')
        ->and((string) eventRow($recentProcessed->id)?->payload)->toContain('requirements');

    // Idempotent: a second run changes nothing.
    artisanCommand('axispay:provider-events:purge')->assertSuccessful();
    expect(eventRow($oldProcessed->id)?->payload)->toBe($reduced?->payload);
});

it('is scheduled daily without overlapping, on one server', function (): void {
    $event = collect(app(Schedule::class)->events())->firstOrFail(static fn (Event $event): bool => str_contains((string) $event->command, 'axispay:provider-events:purge'));

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
