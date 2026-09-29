<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform favicon (ADR-0053), platform-level (no tenant): one row per
 * generated size (32, 180, 192), the square PNG bytes plus metadata, stored
 * in the database like the platform logo (the containers have no persistent
 * disk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_favicons', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->unsignedSmallInteger('size')->unique();
            // Random per upload: part of the URL, so a new favicon gets a new
            // URL and the old one can be cached forever.
            $table->ulidAscii('version');
            $table->asciiString('mime_type', 32);
            $table->unsignedInteger('size_bytes');
            $table->asciiChar('sha256', 64);
            $table->binary('content');
            $table->ulidAscii('uploaded_by_platform_admin_id')->nullable();
            $table->datetimes(6);
        });

        // BLOB holds 64 KB; a 192 × 192 PNG of a photo can exceed it.
        DB::statement('ALTER TABLE platform_favicons MODIFY content MEDIUMBLOB NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_favicons');
    }
};
