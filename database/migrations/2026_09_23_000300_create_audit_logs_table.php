<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log (plan 7.1). `tenant_id` is NULL for platform events.
 * Two triggers reject UPDATE and DELETE at the database level, so even writes
 * that bypass Eloquent cannot alter the trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id')->nullable();
            $table->string('actor_type', 32);
            $table->foreignUlidAscii('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->asciiString('subject_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->asciiString('request_id', 128)->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['tenant_id', 'created_at'], 'ix_audit_logs_tenant_created');
            $table->index(['action', 'created_at'], 'ix_audit_logs_action_created');
            $table->index(['actor_type', 'actor_id'], 'ix_audit_logs_actor');
            $table->foreign('tenant_id', 'fk_audit_logs_tenant')->references('id')->on('tenants')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_logs_no_update BEFORE UPDATE ON audit_logs
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_logs_no_delete BEFORE DELETE ON audit_logs
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_no_delete');
        Schema::dropIfExists('audit_logs');
    }
};
