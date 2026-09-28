<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gateway's card fingerprint (the same card gives the same value on one
 * account) on attempts and declines, for card-testing forensics
 * (ADR-0051). Never shown to payers; the tenant panel shows the last four
 * digits only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->asciiString('card_fingerprint', 64)->nullable()->after('card_last4');
        });

        Schema::table('payment_attempt_failures', function (Blueprint $table): void {
            $table->asciiString('card_fingerprint', 64)->nullable()->after('card_brand');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempt_failures', function (Blueprint $table): void {
            $table->dropColumn('card_fingerprint');
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('card_fingerprint');
        });
    }
};
