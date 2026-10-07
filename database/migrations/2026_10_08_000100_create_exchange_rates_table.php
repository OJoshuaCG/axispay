<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Published exchange rates (plan 7.1 `exchange_rates`, 13.3, ADR-0063):
 * platform-level, no tenant. FetchBanxicoFixJob stores the FIX of a day once
 * (`rate_date` is the publication date); the checkout only reads this table.
 * `requires_review` marks a FIX that moved more than the sanity limit from
 * the previous one: kept for the superadmins, never used for a charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->asciiString('source', 16);
            $table->asciiChar('base_currency', 3);
            $table->asciiChar('quote_currency', 3);
            // DECIMAL(18,6): exchange rates are never floats (rules.md rule 1).
            $table->decimal('rate', 18, 6);
            $table->date('rate_date');
            $table->dateTime('fetched_at', 6);
            $table->json('raw_payload')->nullable();
            $table->boolean('requires_review')->default(false);
            $table->datetimes(6);

            $table->unique(['source', 'base_currency', 'quote_currency', 'rate_date'], 'uq_exchange_rates_day');
            $table->index(['source', 'base_currency', 'quote_currency', 'rate_date'], 'ix_exchange_rates_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
