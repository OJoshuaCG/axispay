<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The merchant's logo (ADR-0056 part B): a light variant and an optional
 * dark one, one row per variant and tenant, stored IN THE DATABASE like the
 * platform logo (ADR-0053: the containers have no persistent disk), as the
 * normalized PNG bytes (at most 800 × 240) plus metadata.
 *
 * No `livemode`: the logo is the merchant's public identity, like its display
 * name and legal texts, the same for test and live payers; it holds no test
 * or live business data (rules.md rule 4 covers business records).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_logos', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->foreignUlidAscii('tenant_id');
            $table->asciiString('variant', 16);
            // Random per upload: part of the URL, so a new logo gets a new URL
            // and the old one can be cached forever.
            $table->ulidAscii('version');
            $table->asciiString('mime_type', 32);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->asciiChar('sha256', 64);
            $table->binary('content');
            $table->foreignUlidAscii('uploaded_by_user_id')->nullable();
            $table->datetimes(6);

            $table->unique(['tenant_id', 'variant'], 'uq_tenant_logos_variant');
            $table->unique(['tenant_id', 'id'], 'uq_tenant_logos_tenant_id');
            $table->foreign('tenant_id', 'fk_tenant_logos_tenant')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'uploaded_by_user_id'], 'fk_tenant_logos_uploaded_by')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        // BLOB holds 64 KB; a normalized logo stays far below 1 MB.
        DB::statement('ALTER TABLE tenant_logos MODIFY content MEDIUMBLOB NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_logos');
    }
};
