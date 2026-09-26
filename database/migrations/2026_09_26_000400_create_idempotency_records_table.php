<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API idempotency (plan 7.8, 10.3). One row per (tenant, mode, key): the
 * fingerprint of the first request and, once it finished, its response.
 * `locked_until` and `lock_token` mark the request still in progress. Rows expire after 24
 * hours and are purged by `axispay:idempotency:purge`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->foreignUlidAscii('api_key_id');
            $table->asciiString('idempotency_key', 255);
            $table->asciiString('request_method', 8);
            $table->asciiString('request_path', 255);
            $table->asciiChar('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            // The exact bytes sent, whatever they are (not a JSON column: an
            // unexpected body must never make storing the answer fail).
            $table->longText('response_body')->nullable();
            $table->dateTime('locked_until', 6)->nullable();
            // Token of the request that holds the key; a request that lost
            // the key (taken over after its lock expired) can no longer
            // store or release it.
            $table->asciiChar('lock_token', 32)->nullable();
            $table->dateTime('expires_at', 6);
            $table->datetimes(6);

            $table->unique(['tenant_id', 'livemode', 'idempotency_key'], 'uq_idempotency_records_key');
            $table->index('expires_at', 'ix_idempotency_records_expires_at');
            $table->foreign('tenant_id', 'fk_idempotency_records_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'api_key_id'], 'fk_idempotency_records_api_key')
                ->references(['tenant_id', 'id'])->on('api_keys')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
