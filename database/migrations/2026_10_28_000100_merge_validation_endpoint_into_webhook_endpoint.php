<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One webhook endpoint per tenant and mode that also answers the pre-payment
 * validation (ADR-0069):
 *
 *  - `webhook_endpoints` gains the validation settings that lived in
 *    `validation_endpoints` (failure policy, default for new links and the
 *    failure counters of the panel's alert). The URL and the secret are the
 *    endpoint's own.
 *  - `validator_slot` is `1` while the endpoint subscribes to
 *    `payment.pre_validation` and NULL otherwise (the model keeps it in
 *    sync). MariaDB lets a unique index hold many NULLs, so the unique index
 *    below allows any number of endpoints per tenant and mode but only one
 *    validator.
 *  - `validation_calls.validation_endpoint_id` becomes `webhook_endpoint_id`.
 *    It still has no foreign key on purpose: the log of a link outlives the
 *    endpoint that answered it.
 *  - `validation_endpoints` is dropped. Nothing is in production; a
 *    development or staging database that had a validation URL must
 *    configure it again in the webhook endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            // `fail_closed` (default) or `fail_open` (ValidationFailurePolicy).
            $table->asciiString('validation_failure_policy', 16)->default('fail_closed')->after('disabled_at');
            $table->boolean('validation_enabled_by_default')->default(false)->after('validation_failure_policy');
            $table->unsignedInteger('validation_consecutive_failures')->default(0)->after('validation_enabled_by_default');
            $table->dateTime('validation_last_failure_at', 6)->nullable()->after('validation_consecutive_failures');
            $table->dateTime('validation_last_success_at', 6)->nullable()->after('validation_last_failure_at');
            // Last failure e-mail (at most one per hour).
            $table->dateTime('validation_last_alerted_at', 6)->nullable()->after('validation_last_success_at');
            // 1 while subscribed to `payment.pre_validation`, NULL otherwise.
            $table->unsignedTinyInteger('validator_slot')->nullable()->after('validation_last_alerted_at');

            $table->unique(['tenant_id', 'livemode', 'validator_slot'], 'uq_webhook_endpoints_validator');
        });

        Schema::table('validation_calls', function (Blueprint $table): void {
            $table->dropIndex('ix_validation_calls_endpoint');
            $table->renameColumn('validation_endpoint_id', 'webhook_endpoint_id');
        });

        Schema::table('validation_calls', function (Blueprint $table): void {
            $table->index(['tenant_id', 'livemode', 'webhook_endpoint_id', 'id'], 'ix_validation_calls_endpoint');
        });

        Schema::dropIfExists('validation_endpoints');
    }

    public function down(): void
    {
        Schema::create('validation_endpoints', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->string('url', 2048);
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->dateTime('previous_secret_expires_at', 6)->nullable();
            $table->boolean('enabled_by_default')->default(false);
            $table->asciiString('failure_policy', 16);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->dateTime('last_failure_at', 6)->nullable();
            $table->dateTime('last_success_at', 6)->nullable();
            $table->dateTime('last_alerted_at', 6)->nullable();
            $table->foreignUlidAscii('created_by_user_id')->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_validation_endpoints_tenant_id');
            $table->unique(['tenant_id', 'livemode'], 'uq_validation_endpoints_tenant_mode');
            $table->foreign('tenant_id', 'fk_validation_endpoints_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::table('validation_calls', function (Blueprint $table): void {
            $table->dropIndex('ix_validation_calls_endpoint');
            $table->renameColumn('webhook_endpoint_id', 'validation_endpoint_id');
        });

        Schema::table('validation_calls', function (Blueprint $table): void {
            $table->index(['tenant_id', 'livemode', 'validation_endpoint_id', 'id'], 'ix_validation_calls_endpoint');
        });

        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropUnique('uq_webhook_endpoints_validator');
            $table->dropColumn([
                'validation_failure_policy',
                'validation_enabled_by_default',
                'validation_consecutive_failures',
                'validation_last_failure_at',
                'validation_last_success_at',
                'validation_last_alerted_at',
                'validator_slot',
            ]);
        });
    }
};
