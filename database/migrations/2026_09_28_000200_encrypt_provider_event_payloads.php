<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incoming gateway events are encrypted at rest (ADR-0051): an event body
 * may carry account or payer details, so `payload` becomes ciphertext
 * (Laravel's `encrypted` cast, APP_KEY) in a LONGTEXT column.
 * `payload_reduced` tells whether only the reduced envelope is kept, so the
 * retention purge no longer needs to read payloads to find the ones left to
 * reduce. Existing rows are converted in place, in chunks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_events', function (Blueprint $table): void {
            $table->boolean('payload_reduced')->default(false)->after('payload');
        });

        DB::table('provider_events')->where('payload', 'like', '%"axispay_reduced":true%')->update(['payload_reduced' => true]);

        Schema::table('provider_events', function (Blueprint $table): void {
            $table->longText('payload')->change();
        });

        DB::table('provider_events')->select(['id', 'payload'])->orderBy('id')->chunkById(500, static function ($rows): void {
            foreach ($rows as $row) {
                $payload = is_string($row->payload ?? null) ? $row->payload : '{}';
                DB::table('provider_events')->where('id', $row->id)->update(['payload' => Crypt::encryptString($payload)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('provider_events')->select(['id', 'payload'])->orderBy('id')->chunkById(500, static function ($rows): void {
            foreach ($rows as $row) {
                $payload = is_string($row->payload ?? null) ? $row->payload : '';
                DB::table('provider_events')->where('id', $row->id)->update(['payload' => Crypt::decryptString($payload)]);
            }
        });

        Schema::table('provider_events', function (Blueprint $table): void {
            $table->json('payload')->change();
            $table->dropColumn('payload_reduced');
        });
    }
};
