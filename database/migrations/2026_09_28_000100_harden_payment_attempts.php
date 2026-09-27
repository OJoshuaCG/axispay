<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 hardening (ADR-0051):
 *
 *  - `payment_attempts.confirmation_lease_token`: who holds the lease, so
 *    only the holder can release it;
 *  - `payment_attempts.validation_outcome` / `validation_payer_message`: the
 *    merchant's decision before capture is kept, so a retry, a webhook or
 *    the reconciliation never asks again and never captures a rejected
 *    authorization;
 *  - `livemode` on `payment_attempt_failures` and `payer_details` (rules.md
 *    rule 4), filled from their attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->asciiChar('confirmation_lease_token', 32)->nullable()->after('confirmation_lease_until');
            $table->asciiString('validation_outcome', 16)->nullable()->after('authorized_at');
            $table->string('validation_payer_message', 500)->nullable()->after('validation_outcome');
        });

        foreach (['payment_attempt_failures', 'payer_details'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('livemode')->default(false)->after('tenant_id');
            });

            DB::statement("UPDATE `{$name}` AS t JOIN `payment_attempts` AS a ON a.`tenant_id` = t.`tenant_id` AND a.`id` = t.`payment_attempt_id` SET t.`livemode` = a.`livemode`");

            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('livemode')->change(); // no default: always written from the context
            });
        }
    }

    public function down(): void
    {
        foreach (['payment_attempt_failures', 'payer_details'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('livemode');
            });
        }

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['confirmation_lease_token', 'validation_outcome', 'validation_payer_message']);
        });
    }
};
