<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment events (Phase 4, ADR-0051) keep the gateway object they are about
 * and our attempt ID read from its metadata, so the handler can re-read the
 * right payment without parsing gateway payloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->asciiString('object_id', 255)->nullable()->after('type');
            $table->foreignUlidAscii('payment_attempt_id')->nullable()->after('gateway_connection_id');
        });
    }

    public function down(): void
    {
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->dropColumn(['object_id', 'payment_attempt_id']);
        });
    }
};
