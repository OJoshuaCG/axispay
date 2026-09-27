<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business events of a tenant (`payment_link.opened`, `payment.succeeded`,
 * `payment.failed`, `payment_link.paid`...), written in the same transaction
 * as the change they describe. Phase 5 turns each one into the outgoing
 * webhook outbox (plan 7.6 `webhook_events`, 15.4); until then they are only
 * recorded (`published_at` stays NULL). `data` holds our identifiers and
 * facts, never payer data or gateway identifiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->asciiString('type', 64);
            $table->asciiString('subject_type', 32);
            $table->foreignUlidAscii('subject_id');
            $table->json('data');
            $table->dateTime('occurred_at', 6);
            $table->dateTime('published_at', 6)->nullable();
            $table->datetimes(6);

            $table->index(['tenant_id', 'livemode', 'published_at', 'id'], 'ix_domain_events_unpublished');
            $table->index(['tenant_id', 'subject_id'], 'ix_domain_events_subject');
            $table->foreign('tenant_id', 'fk_domain_events_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
