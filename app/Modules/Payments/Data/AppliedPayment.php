<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * Result of applying a gateway payment to its attempt: the rows as they are
 * now, and whether this application recorded a new decline.
 */
final readonly class AppliedPayment
{
    public function __construct(
        public PaymentAttempt $attempt,
        public PaymentLink $link,
        public bool $newDecline,
    ) {}
}
