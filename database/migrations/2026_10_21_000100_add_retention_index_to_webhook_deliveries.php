<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 15.6: the delivery log is kept 30 days (ADR-0067). The daily purge
 * deletes by age across tenants, so it needs an index on `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->index('created_at', 'ix_webhook_deliveries_retention');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex('ix_webhook_deliveries_retention');
        });
    }
};
