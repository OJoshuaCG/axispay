<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audited impersonation sessions (plan 17.4). The hand-off token is stored as
 * a SHA-256 and cleared once consumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->foreignUlidAscii('user_id');
            $table->foreignUlidAscii('platform_admin_id');
            $table->string('reason', 500);
            $table->asciiChar('token_hash', 64)->nullable()->unique();
            $table->dateTime('expires_at', 6);
            $table->dateTime('consumed_at', 6)->nullable();
            $table->dateTime('ended_at', 6)->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->datetimes(6);

            $table->foreign('tenant_id', 'fk_impersonation_sessions_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'user_id'], 'fk_impersonation_sessions_user')->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign('platform_admin_id', 'fk_impersonation_sessions_admin')->references('id')->on('platform_admins')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
