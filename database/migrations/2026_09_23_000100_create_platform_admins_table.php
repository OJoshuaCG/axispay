<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform operators (plan 7.1, 17.4): separate from tenant users, own guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->string('name', 120);
            $table->string('email', 254)->unique();
            $table->string('password');
            $table->string('role', 32)->default('support_readonly');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->dateTime('two_factor_confirmed_at', 6)->nullable();
            $table->dateTime('last_login_at', 6)->nullable();
            $table->dateTime('disabled_at', 6)->nullable();
            $table->rememberToken();
            $table->datetimes(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
