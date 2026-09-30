<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outgoing webhooks (plan 7.6, 15.1-15.7, ADR-0057):
 *
 *  - `webhook_endpoints`: a tenant's destinations per mode. The signing
 *    secret (and the previous one, valid 24 hours after a rotation) is stored
 *    encrypted, never hashed, because it is needed to sign.
 *  - `webhook_events`: the outbox, one row per business event and tenant.
 *    `payload` is the exact body sent, frozen at creation and identical in
 *    every retry; the row ID is the public `evt_...` and the `webhook-id`.
 *  - `webhook_deliveries`: one row per endpoint and per attempt.
 *
 * Every reference between tenant tables includes the tenant (plan 6.4).
 * The outbox sweeper reads unpublished domain events across tenants, oldest
 * first: `ix_domain_events_outbox_sweep` serves it without scanning the
 * published history (NULL `published_at` first, in ID order, the cutoff
 * checked in the index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_events', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'uq_domain_events_tenant_id');
            $table->index(['published_at', 'id', 'occurred_at'], 'ix_domain_events_outbox_sweep');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->string('url', 2048);
            $table->string('description', 255)->nullable();
            // A list of event types, or ["*"] for every event.
            $table->json('enabled_events');
            // Laravel `encrypted` cast; plaintext form `whsec_<base64>`.
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->dateTime('previous_secret_expires_at', 6)->nullable();
            $table->asciiString('status', 32);
            $table->dateTime('failing_since', 6)->nullable();
            $table->dateTime('disabled_at', 6)->nullable();
            $table->foreignUlidAscii('created_by_user_id')->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_webhook_endpoints_tenant_id');
            $table->index(['tenant_id', 'livemode', 'status'], 'ix_webhook_endpoints_tenant_status');
            $table->foreign('tenant_id', 'fk_webhook_endpoints_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->asciiString('type', 64);
            // NULL for `ping` (sent from the panel, not a business event).
            $table->foreignUlidAscii('domain_event_id')->nullable();
            // The exact body sent (plan 15.3), kept byte for byte.
            $table->json('payload');
            $table->dateTime('dispatched_at', 6)->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_webhook_events_tenant_id');
            // One webhook event per business event, whoever publishes it.
            $table->unique('domain_event_id', 'uq_webhook_events_domain_event');
            $table->index(['tenant_id', 'livemode', 'id'], 'ix_webhook_events_list');
            $table->index(['tenant_id', 'livemode', 'type', 'id'], 'ix_webhook_events_type');
            $table->foreign('tenant_id', 'fk_webhook_events_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'domain_event_id'], 'fk_webhook_events_domain_event')
                ->references(['tenant_id', 'id'])->on('domain_events')->restrictOnDelete();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->foreignUlidAscii('webhook_event_id');
            $table->foreignUlidAscii('webhook_endpoint_id');
            // `automatic` (the outbox and its retries), `manual` (resend from
            // the panel) or `test` (ping). Attempts are numbered per trigger.
            $table->asciiString('trigger', 16);
            $table->unsignedSmallInteger('attempt_number');
            $table->asciiString('status', 16);
            $table->dateTime('scheduled_at', 6);
            // When a job was last queued for it, and the lease of the job
            // sending it (a duplicated job never sends twice).
            $table->dateTime('queued_at', 6)->nullable();
            $table->dateTime('lease_until', 6)->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            // At most 2 KB, sanitized (plan 7.6).
            $table->text('response_body_excerpt')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // timeout, dns_error, tls_error, blocked_destination... (WebhookDeliveryError).
            $table->asciiString('error', 32)->nullable();
            $table->dateTime('next_retry_at', 6)->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_webhook_deliveries_tenant_id');
            $table->unique(['webhook_event_id', 'webhook_endpoint_id', 'trigger', 'attempt_number'], 'uq_webhook_deliveries_attempt');
            $table->index(['tenant_id', 'webhook_endpoint_id', 'id'], 'ix_webhook_deliveries_endpoint');
            $table->index(['tenant_id', 'webhook_event_id'], 'ix_webhook_deliveries_event');
            $table->index(['status', 'scheduled_at'], 'ix_webhook_deliveries_due');

            $table->foreign('tenant_id', 'fk_webhook_deliveries_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'webhook_event_id'], 'fk_webhook_deliveries_event')
                ->references(['tenant_id', 'id'])->on('webhook_events')->restrictOnDelete();
            // Deleting an endpoint deletes its delivery log.
            $table->foreign(['tenant_id', 'webhook_endpoint_id'], 'fk_webhook_deliveries_endpoint')
                ->references(['tenant_id', 'id'])->on('webhook_endpoints')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_endpoints');

        Schema::table('domain_events', function (Blueprint $table): void {
            $table->dropIndex('ix_domain_events_outbox_sweep');
            $table->dropUnique('uq_domain_events_tenant_id');
        });
    }
};
