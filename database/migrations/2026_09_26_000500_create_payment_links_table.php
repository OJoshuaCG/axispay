<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment links (plan 7.5, ADR-006). `public_token` is an independent
 * high-entropy secret (plan 11.1), never derived from the ID. The unique
 * `(tenant_id, id)` lets tenant tables of later phases (payment_attempts,
 * fx_quotes) reference a link with a composite foreign key (plan 6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->asciiString('public_token', 64)->unique('uq_payment_links_public_token');
            $table->asciiString('status', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->asciiChar('currency', 3);
            $table->string('description', 500);
            $table->json('metadata')->nullable();
            $table->string('client_reference_id', 200)->nullable();
            $table->asciiString('fx_mode', 16)->default('none');
            $table->decimal('fx_fixed_rate', 18, 6)->nullable();
            $table->json('payer_fields_config');
            $table->string('return_url', 2048)->nullable();
            $table->boolean('pre_payment_validation')->default(false);
            $table->asciiString('locale', 5);
            $table->dateTime('expires_at', 6);
            $table->dateTime('paid_at', 6)->nullable();
            $table->dateTime('canceled_at', 6)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->dateTime('expired_at', 6)->nullable();
            $table->dateTime('first_opened_at', 6)->nullable();
            $table->dateTime('last_opened_at', 6)->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->asciiString('refund_status', 16)->default('none');
            $table->asciiString('dispute_status', 16)->default('none');
            $table->asciiString('created_via', 16);
            $table->asciiString('created_by_actor_type', 32);
            $table->asciiString('created_by_actor_id', 26)->nullable();
            $table->asciiString('idempotency_key', 255)->nullable();
            // Fingerprint of the create request, so the key can never be reused
            // with another body, even after its idempotency record expired.
            $table->asciiChar('idempotency_request_hash', 64)->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_payment_links_tenant_id');
            $table->unique(['tenant_id', 'livemode', 'idempotency_key'], 'uq_payment_links_idempotency_key');
            // Lists are newest first by ID (ULIDs sort by creation time):
            // with and without a status filter, and the ID-ordered walk of a
            // mode's active links, read these in order without sorting.
            $table->index(['tenant_id', 'livemode', 'status', 'id'], 'ix_payment_links_tenant_status');
            $table->index(['tenant_id', 'livemode', 'id'], 'ix_payment_links_tenant_mode');
            $table->index(['tenant_id', 'livemode', 'client_reference_id'], 'ix_payment_links_client_reference');
            $table->index(['status', 'expires_at'], 'ix_payment_links_expiration');
            $table->foreign('tenant_id', 'fk_payment_links_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
