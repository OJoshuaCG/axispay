<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenants and tenant users (plan 7.1, 7.2). ULID keys in ascii_bin, DATETIME(6)
 * in UTC. `users.email` is globally unique: one tenant per user in the MVP.
 * `(tenant_id, id)` is unique so child tables can reference users with a
 * composite foreign key that includes the tenant (plan 6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->string('legal_name', 200);
            $table->string('display_name', 120);
            $table->string('status', 32)->default('pending_onboarding')->index();
            $table->string('status_reason', 500)->nullable();
            $table->dateTime('status_changed_at', 6)->nullable();
            $table->string('timezone', 64)->default('America/Mexico_City');
            $table->string('default_locale', 5)->default('es');
            $table->string('support_email', 254)->nullable();
            $table->string('privacy_notice_url', 2048)->nullable();
            $table->json('allowed_return_domains')->nullable();
            $table->json('settings')->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->datetimes(6);
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->string('name', 120);
            $table->string('email', 254)->unique();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->dateTime('two_factor_confirmed_at', 6)->nullable();
            $table->dateTime('email_verified_at', 6)->nullable();
            $table->dateTime('last_login_at', 6)->nullable();
            $table->dateTime('disabled_at', 6)->nullable();
            $table->rememberToken();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'id'], 'uq_users_tenant_id');
            $table->foreign('tenant_id', 'fk_users_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 254)->primary();
            $table->string('token');
            $table->dateTime('created_at', 6)->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            // Tenant users and platform admins both use ULIDs.
            $table->char('user_id', 26)->charset('ascii')->collation('ascii_bin')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('tenants');
    }
};
