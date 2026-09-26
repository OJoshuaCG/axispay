<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incoming gateway webhooks (plan 7.6, 14). The unique `provider_event_id`
 * makes a duplicate delivery a no-op (plan 26.2 case 3). `tenant_id` stays
 * NULL for unroutable events (platform rows, ADR-0031). `payload` is the raw
 * body as received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->asciiString('provider', 32);
            $table->asciiString('provider_event_id', 255)->unique('uq_provider_events_event_id');
            $table->asciiString('provider_account_id', 255)->nullable();
            $table->boolean('livemode');
            $table->asciiString('type', 128);
            $table->json('payload');
            $table->foreignUlidAscii('tenant_id')->nullable();
            $table->foreignUlidAscii('gateway_connection_id')->nullable();
            $table->asciiString('status', 32);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->dateTime('received_at', 6);
            $table->dateTime('processed_at', 6)->nullable();
            $table->datetimes(6);

            $table->index(['status', 'received_at'], 'ix_provider_events_status_received');
            $table->index(['tenant_id', 'received_at'], 'ix_provider_events_tenant_received');

            $table->foreign('tenant_id', 'fk_provider_events_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'gateway_connection_id'], 'fk_provider_events_connection')
                ->references(['tenant_id', 'id'])->on('gateway_connections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
