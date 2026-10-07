<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds and disputes of a payment (plan 7.5, 16; ADR-0066). Both reference
 * their payment attempt with the composite foreign key of plan 6.4.
 *
 * `refunds`: one row per refund, whoever made it (`origin`: the API, the panel
 * or the gateway's own dashboard). `provider_refund_id` stays NULL until the
 * gateway answers (a refund whose answer was lost is found again by its
 * reference). `idempotency_key` / `idempotency_request_hash` are the defense
 * in depth under the idempotency records, as on `payment_links`: the same key
 * with another body is refused even after the record expired. The amounts of
 * pending and succeeded refunds are what is no longer refundable.
 *
 * `disputes`: one row per dispute the gateway opens; the merchant answers it
 * in the gateway's own dashboard, the platform records and tells (plan 16.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->foreignUlidAscii('payment_attempt_id');
            $table->asciiString('provider_refund_id', 255)->nullable();
            // In the currency charged (MXN when a conversion happened).
            $table->unsignedBigInteger('amount_minor');
            $table->asciiChar('currency', 3);
            // `pending` | `succeeded` | `failed` | `canceled`.
            $table->asciiString('status', 16);
            // `requested_by_customer` | `duplicate` | `fraudulent` | `other`.
            $table->asciiString('reason', 32);
            // Generic code shown to the integrator (`refund_failed`...), never the gateway's own reason.
            $table->asciiString('failure_reason', 64)->nullable();
            // `api` | `panel` | `provider_dashboard`.
            $table->asciiString('origin', 24);
            $table->asciiString('created_by_actor_type', 32);
            $table->asciiString('created_by_actor_id', 26)->nullable();
            $table->asciiString('idempotency_key', 255)->nullable();
            $table->asciiChar('idempotency_request_hash', 64)->nullable();
            $table->dateTime('succeeded_at', 6)->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_refunds_tenant_id');
            $table->unique('provider_refund_id', 'uq_refunds_provider_refund');
            $table->unique(['tenant_id', 'livemode', 'idempotency_key'], 'uq_refunds_idempotency_key');
            $table->index(['tenant_id', 'payment_attempt_id', 'id'], 'ix_refunds_payment');
            $table->index(['tenant_id', 'livemode', 'status', 'id'], 'ix_refunds_tenant_status');
            $table->index(['status', 'updated_at'], 'ix_refunds_reconcile');

            $table->foreign('tenant_id', 'fk_refunds_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_attempt_id'], 'fk_refunds_attempt')
                ->references(['tenant_id', 'id'])->on('payment_attempts')->restrictOnDelete();
        });

        Schema::create('disputes', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->foreignUlidAscii('payment_attempt_id');
            $table->asciiString('provider_dispute_id', 255);
            $table->unsignedBigInteger('amount_minor');
            $table->asciiChar('currency', 3);
            // The gateway's reason, normalized to snake_case text (`fraudulent`, `product_not_received`...).
            $table->asciiString('reason', 64)->nullable();
            // `needs_response` | `under_review` | `won` | `lost` | `warning_closed`.
            $table->asciiString('status', 24);
            $table->dateTime('evidence_due_by', 6)->nullable();
            $table->dateTime('opened_at', 6);
            $table->dateTime('closed_at', 6)->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_disputes_tenant_id');
            $table->unique('provider_dispute_id', 'uq_disputes_provider_dispute');
            $table->index(['tenant_id', 'payment_attempt_id', 'id'], 'ix_disputes_payment');
            $table->index(['tenant_id', 'livemode', 'status', 'id'], 'ix_disputes_tenant_status');

            $table->foreign('tenant_id', 'fk_disputes_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_attempt_id'], 'fk_disputes_attempt')
                ->references(['tenant_id', 'id'])->on('payment_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('refunds');
    }
};
