<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\Refund;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Time\IsoDateTime;

/**
 * The `refund` object of the public API (plan 10.7), as `GET /v1/refunds`
 * shows it and as the `refund.*` events carry it frozen (ADR-0066). Our
 * identifiers only: no gateway ID, and a failure is a generic code, never the
 * gateway's own reason (ADR-019). The amount is in the currency charged.
 */
final class RefundSnapshot
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function of(Refund $refund): array
    {
        return [
            'id' => $refund->prefixedId(),
            'object' => 'refund',
            'livemode' => $refund->livemode,
            'payment' => PrefixedId::encode(ResourceType::Payment, $refund->payment_attempt_id),
            'status' => $refund->status->value,
            ...$refund->money()->toApiArray(),
            'reason' => $refund->reason->value,
            'origin' => $refund->origin->value,
            'failure' => $refund->failure_reason !== null ? ['code' => $refund->failure_reason] : null,
            'created_at' => $refund->created_at !== null ? IsoDateTime::format($refund->created_at) : null,
            'succeeded_at' => $refund->succeeded_at !== null ? IsoDateTime::format($refund->succeeded_at) : null,
        ];
    }
}
