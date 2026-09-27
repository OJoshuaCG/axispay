<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * Cross-tenant reader for the reconciliation scheduler (plan 6.3, 12.5; on
 * the scope-bypass whitelist, config/tenancy.php): which (tenant, mode)
 * pairs have attempts that are not final or links in `processing`.
 * Identifiers only; the job then runs inside each tenant's context.
 */
final class PaymentAttemptLookup
{
    /**
     * @return list<array{tenant_id: string, livemode: bool}>
     */
    public function scopesToReconcile(): array
    {
        $scopes = [];

        $attempts = PaymentAttempt::query()->withoutGlobalScopes()
            ->select(['tenant_id', 'livemode'])
            ->whereIn('status', PaymentAttemptStatus::activeValues())
            ->distinct()
            ->get();

        $links = PaymentLink::query()->withoutGlobalScopes()
            ->select(['tenant_id', 'livemode'])
            ->where('status', PaymentLinkStatus::Processing->value)
            ->distinct()
            ->get();

        foreach ([...$attempts->all(), ...$links->all()] as $row) {
            $scopes[$row->tenant_id.':'.($row->livemode ? '1' : '0')] = ['tenant_id' => $row->tenant_id, 'livemode' => $row->livemode];
        }

        ksort($scopes);

        return array_values($scopes);
    }
}
