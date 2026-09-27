<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reconciliation visits attempts under way oldest-visit first
 * (`reconciled_at`, set on every visit, failures included), so a batch of
 * attempts that keep failing never starves the others (ADR-0051).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dateTime('reconciled_at', 6)->nullable()->after('capture_before');
            $table->index(['tenant_id', 'livemode', 'status', 'reconciled_at'], 'ix_payment_attempts_reconcile_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropIndex('ix_payment_attempts_reconcile_cursor');
            $table->dropColumn('reconciled_at');
        });
    }
};
