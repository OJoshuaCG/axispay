<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission tables with teams (team = tenant, ADR-014),
 * adapted to the schema conventions: ULID keys in ascii_bin and DATETIME(6).
 *
 * - `roles.team_id` NULL = global system role (plan 17.2).
 * - `model_has_roles` / `model_has_permissions` carry the tenant in `team_id`;
 *   the composite FK (team_id, model_id) -> users (tenant_id, id) makes it
 *   impossible to assign a role to a user under another tenant (plan 6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->string('name', 100);
            $table->string('guard_name', 50);
            $table->datetimes(6);

            $table->unique(['name', 'guard_name'], 'uq_permissions_name_guard');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('team_id')->nullable();
            $table->string('name', 100);
            $table->string('guard_name', 50);
            $table->datetimes(6);

            $table->index('team_id', 'roles_team_foreign_key_index');
            $table->unique(['team_id', 'name', 'guard_name'], 'uq_roles_team_name_guard');
            $table->foreign('team_id', 'fk_roles_tenant')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->foreignUlidAscii('permission_id');
            $table->string('model_type', 100);
            $table->foreignUlidAscii('model_id');
            $table->foreignUlidAscii('team_id');

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->index('team_id', 'model_has_permissions_team_foreign_key_index');
            $table->primary(['team_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
            $table->foreign('permission_id', 'fk_model_has_permissions_permission')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign(['team_id', 'model_id'], 'fk_model_has_permissions_user')->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignUlidAscii('role_id');
            $table->string('model_type', 100);
            $table->foreignUlidAscii('model_id');
            $table->foreignUlidAscii('team_id');

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->index('team_id', 'model_has_roles_team_foreign_key_index');
            $table->primary(['team_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            $table->foreign('role_id', 'fk_model_has_roles_role')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign(['team_id', 'model_id'], 'fk_model_has_roles_user')->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignUlidAscii('permission_id');
            $table->foreignUlidAscii('role_id');

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            $table->foreign('permission_id', 'fk_role_has_permissions_permission')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id', 'fk_role_has_permissions_role')->references('id')->on('roles')->cascadeOnDelete();
        });

        app('cache')->store()->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
