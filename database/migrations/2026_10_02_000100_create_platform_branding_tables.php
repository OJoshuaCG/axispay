<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform branding (ADR-0053), platform-level (no tenant): the platform
 * logo, light and optional dark variant, stored IN THE DATABASE (the
 * containers have no persistent disk; a redeploy would lose a file), as the
 * normalized PNG bytes plus metadata; and platform settings (key/value),
 * starting with the brand display mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_logos', function (Blueprint $table): void {
            $table->ulidAscii('id')->primary();
            $table->asciiString('variant', 16)->unique();
            // Random per upload: part of the URL, so a new logo gets a new URL
            // and the old one can be cached forever.
            $table->ulidAscii('version');
            $table->asciiString('mime_type', 32);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->asciiChar('sha256', 64);
            $table->binary('content');
            $table->ulidAscii('uploaded_by_platform_admin_id')->nullable();
            $table->datetimes(6);
        });

        // BLOB holds 64 KB; a normalized logo stays far below 1 MB.
        DB::statement('ALTER TABLE platform_logos MODIFY content MEDIUMBLOB NOT NULL');

        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->asciiString('key', 64)->primary();
            $table->json('value');
            $table->datetimes(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_logos');
    }
};
