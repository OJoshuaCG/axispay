<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

use App\Modules\Payments\Models\PaymentAttempt;
use SensitiveParameter;

/**
 * A claimed attempt (ClaimLinkAttempt): the link's single active attempt
 * and the token of the lease this caller now holds on it. The caller frees
 * both with ReleaseLinkAfterAttempt.
 */
final readonly class AttemptClaim
{
    public function __construct(
        public PaymentAttempt $attempt,
        #[SensitiveParameter] public string $leaseToken,
    ) {}
}
