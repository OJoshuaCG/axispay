<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two optional, frozen-at-creation settings of a payment link (ADR-0064):
 *
 *  - `line_items`: the merchant's breakdown of the amount, shown on the
 *    hosted page. A JSON list of `{label, amount_minor, absorbs_rounding}`
 *    in the link's currency; display only, it never changes what is charged.
 *  - `auto_redirect`: send the payer back to `return_url` by themselves,
 *    after a short countdown, once the payment succeeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->json('line_items')->nullable()->after('metadata');
            $table->boolean('auto_redirect')->default(false)->after('return_url');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->dropColumn(['line_items', 'auto_redirect']);
        });
    }
};
