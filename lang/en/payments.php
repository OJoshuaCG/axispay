<?php

declare(strict_types=1);

/*
 * Payment vocabulary. Status labels are read by App\Enums\PaymentStatus::label()
 * as payments.status.<enum value>.
 */
return [

    'status' => [
        'authorized' => 'Authorized',
        'captured' => 'Captured',
        'pending' => 'Pending',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
        'disputed' => 'Disputed',
        'failed' => 'Failed',
        'canceled' => 'Canceled',
        'expired' => 'Expired',
        // Fallback badge for a value outside the enum (see <x-payment-status>).
        'unknown' => 'Unknown status',
    ],

];
