<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use App\Modules\Gateways\Data\ProviderDispute;
use App\Modules\Gateways\Data\ProviderRefund;
use App\Modules\Gateways\Enums\ProviderDisputeStatus;
use App\Modules\Gateways\Enums\ProviderRefundStatus;
use Stripe\Dispute;
use Stripe\Refund;
use Stripe\StripeObject;

/**
 * Stripe Refund and Dispute → ProviderRefund and ProviderDispute (plan 16).
 * The only place that reads their statuses and fields; like the payment
 * mapper, it tolerates a `payment_intent` that is expanded or not.
 */
final class StripeRefundMapper
{
    /** The metadata key our refund ID travels under (read back to find a refund whose answer was lost). */
    public const string REFERENCE_KEY = 'axispay_refund_id';

    public static function toProviderRefund(Refund $refund): ProviderRefund
    {
        return new ProviderRefund(
            providerRefundId: $refund->id,
            status: self::refundStatus((string) $refund->status),
            amountMinor: (int) $refund->amount,
            currency: strtoupper((string) $refund->currency),
            providerPaymentId: self::objectId($refund->payment_intent ?? null),
            failureReason: self::string($refund->failure_reason ?? null),
            reference: self::reference($refund->metadata ?? null),
            createdAt: is_numeric($refund->created ?? null) ? (int) $refund->created : null,
        );
    }

    public static function toProviderDispute(Dispute $dispute): ProviderDispute
    {
        $evidence = $dispute->evidence_details ?? null;
        $dueBy = $evidence instanceof StripeObject ? ($evidence['due_by'] ?? null) : null;

        return new ProviderDispute(
            providerDisputeId: $dispute->id,
            status: self::disputeStatus((string) $dispute->status),
            amountMinor: (int) $dispute->amount,
            currency: strtoupper((string) $dispute->currency),
            providerPaymentId: self::objectId($dispute->payment_intent ?? null),
            reason: self::string($dispute->reason ?? null),
            evidenceDueBy: is_numeric($dueBy) ? (int) $dueBy : null,
            createdAt: is_numeric($dispute->created ?? null) ? (int) $dispute->created : null,
        );
    }

    public static function refundStatus(string $status): ProviderRefundStatus
    {
        return match ($status) {
            Refund::STATUS_SUCCEEDED => ProviderRefundStatus::Succeeded,
            Refund::STATUS_FAILED => ProviderRefundStatus::Failed,
            Refund::STATUS_CANCELED => ProviderRefundStatus::Canceled,
            // `pending` and `requires_action` (the payer must do something at the bank).
            default => ProviderRefundStatus::Pending,
        };
    }

    public static function disputeStatus(string $status): ProviderDisputeStatus
    {
        return match ($status) {
            Dispute::STATUS_UNDER_REVIEW, Dispute::STATUS_WARNING_UNDER_REVIEW => ProviderDisputeStatus::UnderReview,
            Dispute::STATUS_WON => ProviderDisputeStatus::Won,
            Dispute::STATUS_LOST => ProviderDisputeStatus::Lost,
            // `prevented`: the dispute was settled before it became a chargeback.
            Dispute::STATUS_WARNING_CLOSED, Dispute::STATUS_PREVENTED => ProviderDisputeStatus::WarningClosed,
            // `needs_response` and `warning_needs_response`: waiting for the merchant's evidence.
            default => ProviderDisputeStatus::NeedsResponse,
        };
    }

    /** The ID of an object that Stripe sends as an ID or, when expanded, as the object. */
    public static function objectId(mixed $value): ?string
    {
        if ($value instanceof StripeObject) {
            $value = $value['id'] ?? null;
        }

        return self::string($value);
    }

    private static function reference(mixed $metadata): ?string
    {
        $value = match (true) {
            $metadata instanceof StripeObject => $metadata[self::REFERENCE_KEY] ?? null,
            is_array($metadata) => $metadata[self::REFERENCE_KEY] ?? null,
            default => null,
        };

        return self::string($value);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
