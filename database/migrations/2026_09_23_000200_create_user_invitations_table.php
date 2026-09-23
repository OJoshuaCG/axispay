<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations (plan 7.2, 17.3): only the SHA-256 of the token is stored
 * (ascii_bin, unique). References to users include the tenant (plan 6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->string('email', 254);
            $table->string('role_name', 64);
            $table->asciiChar('token_hash', 64)->unique();
            $table->dateTime('expires_at', 6);
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->foreignUlidAscii('invited_by_user_id')->nullable();
            $table->foreignUlidAscii('accepted_user_id')->nullable();
            $table->datetimes(6);

            $table->index(['tenant_id', 'email'], 'ix_user_invitations_tenant_email');
            $table->foreign('tenant_id', 'fk_user_invitations_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invited_by_user_id'], 'fk_user_invitations_inviter')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accepted_user_id'], 'fk_user_invitations_accepted_user')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
