<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook activity checks (ADR-0050: incoming webhooks stay mandatory).
 * `axispay:doctor` and the platform panel ask when the last event arrived:
 *
 *  - per mode: `MAX(received_at)` and "any event since" by provider and
 *    mode, a range on (provider, livemode, received_at);
 *  - per connection: the latest event of each connection of a tenant, and
 *    "no event since" for api_key connections, on (tenant_id,
 *    gateway_connection_id, received_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->index(['provider', 'livemode', 'received_at'], 'ix_provider_events_mode_received');
            $table->index(['tenant_id', 'gateway_connection_id', 'received_at'], 'ix_provider_events_connection_received');
        });
    }

    public function down(): void
    {
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->dropIndex('ix_provider_events_mode_received');
            $table->dropIndex('ix_provider_events_connection_received');
        });
    }
};
