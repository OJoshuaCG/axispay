<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secret that signs the proof appended to a link's `return_url` after a
 * successful payment (ADR-0064): one row per tenant and mode, like the
 * validation endpoint. The secret (and the previous one, valid 24 hours
 * after a rotation) is stored encrypted, never hashed, because it is needed
 * to sign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_signing_secrets', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->boolean('livemode');
            // Laravel `encrypted` cast; plaintext form `rsec_<64 hex>`.
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->dateTime('previous_secret_expires_at', 6)->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_return_signing_secrets_tenant_id');
            $table->unique(['tenant_id', 'livemode'], 'uq_return_signing_secrets_tenant_mode');
            $table->foreign('tenant_id', 'fk_return_signing_secrets_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_signing_secrets');
    }
};
