<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An attempt the platform had to close without asking the gateway (for
 * example a disconnected api_key connection whose credentials are gone) is
 * flagged for review: a confirmation whose answer was lost may have left an
 * authorization hold on the payer's card (ADR-0051).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->boolean('needs_review')->default(false)->after('late_payment');
            $table->asciiString('review_reason', 32)->nullable()->after('needs_review');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['needs_review', 'review_reason']);
        });
    }
};
