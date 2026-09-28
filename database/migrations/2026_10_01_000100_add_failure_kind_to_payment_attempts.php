<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider-neutral kind of the last failed try (card declined,
 * insufficient funds, authentication failed...), next to the gateway's own
 * codes, so the payments domain and the outgoing events never read gateway
 * codes (ADR-0051).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->asciiString('last_failure_kind', 32)->nullable()->after('last_decline_code');
        });

        Schema::table('payment_attempt_failures', function (Blueprint $table): void {
            $table->asciiString('kind', 32)->nullable()->after('decline_code');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempt_failures', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('last_failure_kind');
        });
    }
};
