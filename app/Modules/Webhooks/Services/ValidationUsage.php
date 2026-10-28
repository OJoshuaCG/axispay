<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;

/**
 * Tells whether the current tenant and mode still have open payment links that
 * were promised a pre-payment validation (ADR-0069, decision 7).
 *
 * While there are any, the endpoint that performs the validation cannot be
 * deleted, disabled by hand or unsubscribed from `payment.pre_validation`:
 * a link that asks for a validation and finds none is never charged
 * (fail-closed, ADR-0058), so removing the destination would silently turn
 * those links into links that cannot be paid.
 *
 * Runs inside the tenant context: PaymentLink is tenant and mode scoped.
 */
final readonly class ValidationUsage
{
    /** Links that can still produce a payment attempt. */
    private const array OPEN_STATUSES = [
        PaymentLinkStatus::Active,
        PaymentLinkStatus::Processing,
    ];

    public function hasOpenLinks(): bool
    {
        return PaymentLink::query()
            ->where('pre_payment_validation', true)
            ->whereIn('status', array_map(static fn (PaymentLinkStatus $status): string => $status->value, self::OPEN_STATUSES))
            ->exists();
    }

    /**
     * The one guard every action that would take the validator offline calls.
     *
     * @throws WebhookEndpointNotAllowedException when open links still need it
     */
    public function assertNoOpenLinks(): void
    {
        if ($this->hasOpenLinks()) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::ValidationInUse);
        }
    }
}
