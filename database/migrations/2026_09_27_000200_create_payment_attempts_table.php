<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment attempts (plan 7.5, 9.2, ADR-0050), one per gateway payment. The
 * generated `active_link_id` equals `payment_link_id` while the attempt is not
 * terminal (including `requires_capture`, the "authorized, waiting for
 * capture" stage of ADR-0050) and NULL once it is; its unique index keeps at
 * most one active attempt per link in the database (rules.md rule 9).
 *
 * `payment_attempt_failures` keeps every decline (plan 9.2); `payer_details`
 * holds the payer's data encrypted and apart from the financial rows so it
 * can be purged (plan 19.2). Both reference the attempt with a composite key
 * that includes the tenant (plan 6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->foreignUlidAscii('payment_link_id');
            $table->asciiString('provider', 32);
            $table->asciiString('provider_payment_id', 255)->nullable();
            $table->asciiString('provider_account_id', 255)->nullable();
            $table->foreignUlidAscii('gateway_connection_id');
            $table->asciiString('status', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->asciiChar('currency', 3);
            $table->unsignedBigInteger('original_amount_minor');
            $table->asciiChar('original_currency', 3);
            $table->foreignUlidAscii('fx_quote_id')->nullable();
            $table->asciiChar('card_country', 2)->nullable();
            $table->asciiString('card_brand', 32)->nullable();
            $table->asciiChar('card_last4', 4)->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->asciiString('last_failure_code', 64)->nullable();
            $table->string('last_failure_message', 500)->nullable();
            $table->asciiString('last_decline_code', 64)->nullable();
            $table->asciiString('client_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            // A confirmation in flight holds this lease, so a second tab or a
            // retried request never confirms the same attempt in parallel.
            $table->dateTime('confirmation_lease_until', 6)->nullable();
            $table->dateTime('authorized_at', 6)->nullable();
            $table->dateTime('capture_before', 6)->nullable();
            $table->dateTime('succeeded_at', 6)->nullable();
            $table->dateTime('failed_at', 6)->nullable();
            $table->dateTime('canceled_at', 6)->nullable();
            $table->unsignedBigInteger('amount_refunded_minor')->default(0);
            // Plan 9.1 / ADR-006: the payment succeeded after the link expired or
            // was canceled. Kept for the Phase 5 webhook (`late_payment: true`).
            $table->boolean('late_payment')->default(false);
            $table->datetimes(6);

            // RTRIM: MariaDB refuses a CHAR column in a generated expression
            // (its value depends on PAD_CHAR_TO_FULL_LENGTH); ULIDs never end
            // in spaces, so RTRIM returns the ID unchanged.
            $table->char('active_link_id', 26)->charset('ascii')->collation('ascii_bin')->nullable()
                ->storedAs("IF(`status` IN ('requires_payment_method','requires_confirmation','requires_action','requires_capture','processing'), RTRIM(`payment_link_id`), NULL)");

            $table->unique('active_link_id', 'uq_one_active_attempt_per_link');
            $table->unique(['provider', 'provider_payment_id'], 'uq_payment_attempts_provider_payment');
            $table->unique(['tenant_id', 'id'], 'uq_payment_attempts_tenant_id');
            $table->index(['tenant_id', 'payment_link_id', 'id'], 'ix_payment_attempts_link');
            $table->index(['tenant_id', 'livemode', 'status', 'id'], 'ix_payment_attempts_tenant_status');
            $table->index(['status', 'updated_at'], 'ix_payment_attempts_reconcile');

            $table->foreign('tenant_id', 'fk_payment_attempts_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_link_id'], 'fk_payment_attempts_link')
                ->references(['tenant_id', 'id'])->on('payment_links')->restrictOnDelete();
            $table->foreign(['tenant_id', 'gateway_connection_id'], 'fk_payment_attempts_connection')
                ->references(['tenant_id', 'id'])->on('gateway_connections')->restrictOnDelete();
        });

        Schema::create('payment_attempt_failures', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->foreignUlidAscii('payment_attempt_id');
            // The gateway's identity of this decline (Stripe: the failed charge),
            // so the same decline reported by the checkout, a webhook and the
            // reconciliation is recorded once.
            $table->asciiString('provider_reference', 255);
            $table->asciiString('code', 64)->nullable();
            $table->asciiString('decline_code', 64)->nullable();
            $table->string('message', 500)->nullable();
            $table->asciiChar('card_country', 2)->nullable();
            $table->asciiString('card_brand', 32)->nullable();
            $table->asciiString('client_ip', 45)->nullable();
            $table->datetimes(6);

            $table->unique(['payment_attempt_id', 'provider_reference'], 'uq_payment_attempt_failures_reference');
            $table->index(['tenant_id', 'payment_attempt_id', 'created_at'], 'ix_payment_attempt_failures_attempt');
            $table->foreign('tenant_id', 'fk_payment_attempt_failures_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_attempt_id'], 'fk_payment_attempt_failures_attempt')
                ->references(['tenant_id', 'id'])->on('payment_attempts')->restrictOnDelete();
        });

        Schema::create('payer_details', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->foreignUlidAscii('payment_attempt_id')->unique('uq_payer_details_attempt');
            // Laravel `encrypted:array` ciphertext (APP_KEY). Never logged.
            $table->text('data')->nullable();
            $table->dateTime('purge_after', 6)->nullable();
            $table->dateTime('purged_at', 6)->nullable();
            $table->datetimes(6);

            $table->index(['purge_after'], 'ix_payer_details_purge');
            $table->foreign('tenant_id', 'fk_payer_details_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_attempt_id'], 'fk_payer_details_attempt')
                ->references(['tenant_id', 'id'])->on('payment_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payer_details');
        Schema::dropIfExists('payment_attempt_failures');
        Schema::dropIfExists('payment_attempts');
    }
};
