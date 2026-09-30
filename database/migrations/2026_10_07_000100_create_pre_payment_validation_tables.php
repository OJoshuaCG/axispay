<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-payment validation (plan 7.4, 7.6, 15.8; ADR-024, ADR-0050, ADR-0058):
 *
 *  - `validation_endpoints`: at most one per tenant and mode. The signing
 *    secret (and the previous one, valid 24 hours after a rotation) is stored
 *    encrypted, never hashed, because it is needed to sign. The failure
 *    counters feed the panel's alert and the hourly e-mail.
 *  - `validation_calls`: one row per call to the merchant (and per test
 *    call), kept 30 days. The row ID is the `webhook-id` and the public
 *    `val_...`. The request body carries the payer's e-mail and name, so it
 *    is stored encrypted (rules.md rule 10).
 *    `validation_endpoint_id` has no foreign key on purpose: the log of a
 *    link outlives the removal of the endpoint that answered it.
 *  - `payment_attempts.validation_cancel_link`: the merchant's `cancel_link`
 *    kept with its decision, so whoever voids the authorization (now or
 *    later) also cancels the link.
 *
 * Every reference between tenant tables includes the tenant (plan 6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('validation_endpoints', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->string('url', 2048);
            // Laravel `encrypted` cast; plaintext form `whsec_<base64>`.
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->dateTime('previous_secret_expires_at', 6)->nullable();
            $table->boolean('enabled_by_default')->default(false);
            // `fail_closed` (default) or `fail_open` (ValidationFailurePolicy).
            $table->asciiString('failure_policy', 16);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->dateTime('last_failure_at', 6)->nullable();
            $table->dateTime('last_success_at', 6)->nullable();
            // Last failure e-mail (at most one per hour).
            $table->dateTime('last_alerted_at', 6)->nullable();
            $table->foreignUlidAscii('created_by_user_id')->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_validation_endpoints_tenant_id');
            $table->unique(['tenant_id', 'livemode'], 'uq_validation_endpoints_tenant_mode');
            $table->foreign('tenant_id', 'fk_validation_endpoints_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('validation_calls', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->ulidAscii('validation_endpoint_id')->nullable();
            // NULL for a test call from the panel.
            $table->ulidAscii('payment_link_id')->nullable();
            $table->ulidAscii('payment_attempt_id')->nullable();
            $table->unsignedSmallInteger('attempt_number')->nullable();
            $table->boolean('is_test')->default(false);
            // `encrypted:array` cast: the exact body sent (holds payer data).
            $table->text('request_payload');
            // NULL while the call is in flight.
            $table->asciiString('outcome', 16)->nullable();
            $table->asciiString('failure_kind', 32)->nullable();
            $table->asciiString('policy_applied', 16)->nullable();
            // `charge` or `block`; NULL for a test call.
            $table->asciiString('final_decision', 16)->nullable();
            $table->asciiString('reason_code', 64)->nullable();
            $table->string('payer_message', 200)->nullable();
            $table->boolean('cancel_link')->default(false);
            $table->boolean('connection_retried')->default(false);
            $table->unsignedSmallInteger('response_status')->nullable();
            // At most 2 KB, sanitized (plan 7.6).
            $table->text('response_body_excerpt')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_validation_calls_tenant_id');
            $table->index(['tenant_id', 'payment_link_id', 'created_at'], 'ix_validation_calls_link');
            $table->index(['tenant_id', 'livemode', 'validation_endpoint_id', 'id'], 'ix_validation_calls_endpoint');
            $table->index('created_at', 'ix_validation_calls_retention');
            $table->foreign('tenant_id', 'fk_validation_calls_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_link_id'], 'fk_validation_calls_link')
                ->references(['tenant_id', 'id'])->on('payment_links')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_attempt_id'], 'fk_validation_calls_attempt')
                ->references(['tenant_id', 'id'])->on('payment_attempts')->restrictOnDelete();
        });

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->boolean('validation_cancel_link')->default(false)->after('validation_payer_message');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('validation_cancel_link');
        });

        Schema::dropIfExists('validation_calls');
        Schema::dropIfExists('validation_endpoints');
    }
};
