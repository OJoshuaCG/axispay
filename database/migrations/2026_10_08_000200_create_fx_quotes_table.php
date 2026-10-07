<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FX quotes (plan 7.5 `fx_quotes`, 13.4, ADR-0063): the immutable record of
 * the conversion offered to the payer and, once an attempt references it,
 * of the conversion that was charged. Written once; the application never
 * updates a row. References its link with the composite foreign key of
 * plan 6.4 and, for `banxico_fix`, the stored rate it came from.
 *
 * `payment_attempts.fx_quote_id` (a plain column since Phase 4) becomes a
 * composite foreign key here, so an attempt can only point at a quote of its
 * own tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_quotes', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->foreignUlidAscii('payment_link_id');
            // `banxico_fix` | `fixed`.
            $table->asciiString('source', 16);
            $table->foreignUlidAscii('exchange_rate_id')->nullable();
            $table->decimal('rate', 18, 6);
            $table->date('rate_date')->nullable();
            $table->unsignedSmallInteger('markup_bps')->default(0);
            // rate * (1 + markup): the only value used to compute the amount (plan 8.4).
            $table->decimal('effective_rate', 18, 6);
            $table->unsignedBigInteger('original_amount_minor');
            $table->asciiChar('original_currency', 3);
            $table->unsignedBigInteger('converted_amount_minor');
            $table->asciiChar('converted_currency', 3);
            $table->dateTime('expires_at', 6);
            $table->dateTime('created_at', 6);

            $table->unique(['tenant_id', 'id'], 'uq_fx_quotes_tenant_id');
            $table->index(['tenant_id', 'payment_link_id', 'id'], 'ix_fx_quotes_link');
            $table->foreign('tenant_id', 'fk_fx_quotes_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_link_id'], 'fk_fx_quotes_link')
                ->references(['tenant_id', 'id'])->on('payment_links')->restrictOnDelete();
            $table->foreign('exchange_rate_id', 'fk_fx_quotes_exchange_rate')->references('id')->on('exchange_rates')->restrictOnDelete();
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'fx_quote_id'], 'fk_payment_attempts_fx_quote')
                ->references(['tenant_id', 'id'])->on('fx_quotes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropForeign('fk_payment_attempts_fx_quote');
        });

        Schema::dropIfExists('fx_quotes');
    }
};
