<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Payments\Enums\ReviewReason;
use App\Modules\Payments\Models\PaymentAttempt;

/**
 * Flags an attempt "needs review" (ADR-0051): shown on the attempt in the
 * tenant panel and audited once. The caller passes the attempt it locked
 * inside its transaction; flagging an already flagged attempt changes
 * nothing.
 */
final readonly class FlagAttemptForReview
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(PaymentAttempt $locked, ReviewReason $reason): void
    {
        if ($locked->needs_review) {
            return;
        }

        $locked->forceFill(['needs_review' => true, 'review_reason' => $reason])->save();

        $this->audit->record(AuditAction::PaymentNeedsReview, $locked, [
            'reason' => $reason->value,
            'status' => $locked->status->value,
            'payment_link_id' => $locked->payment_link_id,
            'livemode' => $locked->livemode,
        ], actor: Actor::system());
    }
}
