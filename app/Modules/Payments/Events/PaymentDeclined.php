<?php

declare(strict_types=1);

namespace App\Modules\Payments\Events;

/**
 * A new decline was recorded on an attempt (plan 9.2). Dispatched after the
 * transaction that recorded it; the checkout's card-testing protection
 * listens to it (plan 11.7 rule 4).
 */
final readonly class PaymentDeclined
{
    public function __construct(
        public string $tenantId,
        public bool $livemode,
        public string $paymentLinkId,
        public string $paymentAttemptId,
    ) {}
}
