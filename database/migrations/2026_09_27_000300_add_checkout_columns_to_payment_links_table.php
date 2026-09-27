<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout state of a link (plan 11.6, 11.7):
 *
 *  - `checkout_blocked_until` / `checkout_block_reason`: the long card-testing
 *    block (10 declines, 24 hours) that the tenant can lift from the panel;
 *  - `checkout_unblocked_at`: declines before it no longer count towards a
 *    new block;
 *  - `opened_event_at`: when the last `payment_link.opened` event was
 *    recorded (debounce of plan 11.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->dateTime('checkout_blocked_until', 6)->nullable()->after('open_count');
            $table->asciiString('checkout_block_reason', 32)->nullable()->after('checkout_blocked_until');
            $table->dateTime('checkout_unblocked_at', 6)->nullable()->after('checkout_block_reason');
            $table->dateTime('opened_event_at', 6)->nullable()->after('checkout_unblocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->dropColumn(['checkout_blocked_until', 'checkout_block_reason', 'checkout_unblocked_at', 'opened_event_at']);
        });
    }
};
