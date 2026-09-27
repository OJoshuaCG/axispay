<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A tenant was closed (plan 21.3). Dispatched after the status change
 * commits; the payment links module cancels the tenant's active links.
 */
final readonly class TenantClosed implements ShouldDispatchAfterCommit
{
    public function __construct(public string $tenantId) {}
}
