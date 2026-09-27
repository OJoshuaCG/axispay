<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A link stopped accepting payments because it expired or was canceled (plan
 * 9.1). Dispatched after the transaction commits. The payments module
 * cancels the link's waiting gateway payment in response.
 */
final readonly class PaymentLinkClosed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $tenantId,
        public bool $livemode,
        public string $paymentLinkId,
    ) {}
}
