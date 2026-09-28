<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Stripe;

use App\Modules\Gateways\Data\PaymentMethodPreview;
use App\Modules\Gateways\Data\ProviderPayment;
use App\Modules\Gateways\Data\ProviderPaymentFailure;
use App\Modules\Gateways\Enums\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Stripe\PaymentIntent;
use Stripe\StripeObject;

/**
 * Stripe PaymentIntent → ProviderPayment (plan 9.2, 12.1). The only place that
 * reads PaymentIntent statuses and fields. Expects `payment_method` and
 * `latest_charge` expanded (StripeGateway asks for both), and degrades to
 * NULLs when they are not.
 */
final class StripePaymentMapper
{
    /** Longest gateway message kept for the merchant (plan 7.5 `last_failure_message`). */
    private const int MESSAGE_MAX = 300;

    public static function toProviderPayment(PaymentIntent $intent): ProviderPayment
    {
        $status = self::status((string) $intent->status);
        $charge = self::object($intent->latest_charge ?? null);

        return new ProviderPayment(
            providerPaymentId: $intent->id,
            status: $status,
            amountMinor: (int) $intent->amount,
            currency: strtoupper((string) $intent->currency),
            amountCapturableMinor: (int) ($intent->amount_capturable ?? 0),
            clientSecret: $status === ProviderPaymentStatus::RequiresAction && is_string($intent->client_secret ?? null) ? $intent->client_secret : null,
            cardPreview: self::card($intent, $charge),
            failure: self::failure($intent, $status),
            attemptReference: self::attemptReference($intent->metadata ?? null),
            captureBefore: self::captureBefore($charge),
            createdAt: is_numeric($intent->created ?? null) ? (int) $intent->created : null,
        );
    }

    public static function status(string $status): ProviderPaymentStatus
    {
        return match ($status) {
            PaymentIntent::STATUS_REQUIRES_CONFIRMATION => ProviderPaymentStatus::RequiresConfirmation,
            PaymentIntent::STATUS_REQUIRES_ACTION => ProviderPaymentStatus::RequiresAction,
            PaymentIntent::STATUS_REQUIRES_CAPTURE => ProviderPaymentStatus::RequiresCapture,
            PaymentIntent::STATUS_PROCESSING => ProviderPaymentStatus::Processing,
            PaymentIntent::STATUS_SUCCEEDED => ProviderPaymentStatus::Succeeded,
            PaymentIntent::STATUS_CANCELED => ProviderPaymentStatus::Canceled,
            default => ProviderPaymentStatus::RequiresPaymentMethod,
        };
    }

    /** `metadata.axispay_attempt_id` of a payment object (webhook payload or API). */
    public static function attemptReference(mixed $metadata): ?string
    {
        $value = match (true) {
            $metadata instanceof StripeObject => $metadata['axispay_attempt_id'] ?? null,
            is_array($metadata) => $metadata['axispay_attempt_id'] ?? null,
            default => null,
        };

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function card(PaymentIntent $intent, ?StripeObject $charge): ?PaymentMethodPreview
    {
        $card = self::child(self::object($intent->payment_method ?? null), 'card')
            ?? self::child(self::child($charge, 'payment_method_details'), 'card');

        if ($card === null) {
            return null;
        }

        return new PaymentMethodPreview(
            country: self::string($card['country'] ?? null),
            brand: self::string($card['brand'] ?? null),
            last4: self::string($card['last4'] ?? null),
            fingerprint: self::string($card['fingerprint'] ?? null),
        );
    }

    private static function failure(PaymentIntent $intent, ProviderPaymentStatus $status): ?ProviderPaymentFailure
    {
        $error = self::object($intent->last_payment_error ?? null);

        // Stripe keeps last_payment_error after a later success; a failure only
        // counts while the payment is back waiting for a payment method.
        if ($error === null || $status !== ProviderPaymentStatus::RequiresPaymentMethod) {
            return null;
        }

        $latest = $intent->latest_charge ?? null;
        $reference = self::string($error['charge'] ?? null)
            ?? self::string($latest instanceof StripeObject ? ($latest['id'] ?? null) : $latest)
            ?? $intent->id.':'.hash('sha256', (string) json_encode([$error['code'] ?? null, $error['decline_code'] ?? null, self::child($error, 'payment_method')?->offsetGet('id')]));

        $message = self::string($error['message'] ?? null);

        return new ProviderPaymentFailure(
            reference: $reference,
            code: self::string($error['code'] ?? null),
            declineCode: self::string($error['decline_code'] ?? null),
            message: $message !== null ? mb_substr($message, 0, self::MESSAGE_MAX) : null,
        );
    }

    private static function captureBefore(?StripeObject $charge): ?string
    {
        $timestamp = self::child(self::child($charge, 'payment_method_details'), 'card')?->offsetGet('capture_before');

        return is_int($timestamp) ? CarbonImmutable::createFromTimestampUTC($timestamp)->toIso8601String() : null;
    }

    /** A nested Stripe object, read through ArrayAccess (untyped in the SDK). */
    private static function child(?StripeObject $parent, string $key): ?StripeObject
    {
        return $parent !== null && isset($parent[$key]) ? self::object($parent[$key]) : null;
    }

    private static function object(mixed $value): ?StripeObject
    {
        return $value instanceof StripeObject ? $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
