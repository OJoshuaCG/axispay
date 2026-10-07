<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\Dispute;
use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Time\IsoDateTime;

/**
 * The `dispute` object the `dispute.*` events carry (plan 15.2, 16.2), frozen
 * when the event is recorded (ADR-0066). Our identifiers only, no gateway ID.
 * `reason` is the gateway's reason as plain text (`fraudulent`,
 * `product_not_received`...): the integrator needs it to answer the dispute in
 * the gateway's dashboard.
 */
final class DisputeSnapshot
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function of(Dispute $dispute): array
    {
        return [
            'id' => $dispute->prefixedId(),
            'object' => 'dispute',
            'livemode' => $dispute->livemode,
            'payment' => PrefixedId::encode(ResourceType::Payment, $dispute->payment_attempt_id),
            'status' => $dispute->status->value,
            ...$dispute->money()->toApiArray(),
            'reason' => $dispute->reason,
            'evidence_due_by' => $dispute->evidence_due_by !== null ? IsoDateTime::format($dispute->evidence_due_by) : null,
            'opened_at' => IsoDateTime::format($dispute->opened_at),
            'closed_at' => $dispute->closed_at !== null ? IsoDateTime::format($dispute->closed_at) : null,
        ];
    }
}
