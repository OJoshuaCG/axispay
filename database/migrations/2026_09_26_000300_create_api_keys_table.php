<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API keys (plan 7.2, 10.2, ADR-015): owned by the tenant, not by a user.
 * Only the SHA-256 of the key is stored (`key_hash`, ascii_bin, unique);
 * `key_prefix` and `key_last4` identify it in the panel. References to users
 * include the tenant (plan 6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            $table->string('name', 100);
            $table->asciiString('key_prefix', 16);
            $table->asciiString('key_last4', 4);
            $table->asciiChar('key_hash', 64)->unique('uq_api_keys_key_hash');
            $table->json('scopes');
            $table->dateTime('last_used_at', 6)->nullable();
            $table->asciiString('last_used_ip', 45)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->foreignUlidAscii('revoked_by_user_id')->nullable();
            $table->foreignUlidAscii('created_by_user_id')->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_api_keys_tenant_id');
            $table->index(['tenant_id', 'livemode', 'revoked_at'], 'ix_api_keys_tenant_mode');
            $table->foreign('tenant_id', 'fk_api_keys_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by_user_id'], 'fk_api_keys_created_by')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'revoked_by_user_id'], 'fk_api_keys_revoked_by')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
