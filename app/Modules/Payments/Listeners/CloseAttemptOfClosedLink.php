<?php

declare(strict_types=1);

namespace App\Modules\Payments\Listeners;

use App\Modules\PaymentLinks\Events\PaymentLinkClosed;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Jobs\CloseAttemptOfClosedLinkJob;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Tenancy\TenantContext;

/**
 * Plan 9.1: when a link expires or is canceled, its attempt that is still
 * waiting for a payment method is closed and its gateway payment canceled
 * (queued, with retries). An attempt under way never reaches here: the link
 * is `processing` then and neither expires nor can be canceled.
 */
final readonly class CloseAttemptOfClosedLink
{
    public function __construct(private TenantContext $context) {}

    public function handle(PaymentLinkClosed $event): void
    {
        $this->context->runAsTenant($event->tenantId, $event->livemode, static function () use ($event): void {
            $attemptId = PaymentAttempt::query()
                ->where('payment_link_id', $event->paymentLinkId)
                ->whereIn('status', PaymentAttemptStatus::activeValues())
                ->value('id');

            if (is_string($attemptId)) {
                CloseAttemptOfClosedLinkJob::dispatch($attemptId);
            }
        });
    }
}
