<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Checkout\Data\CheckoutResult;
use App\Modules\Checkout\Enums\CheckoutOutcome;
use App\Modules\Gateways\Enums\ProviderFailureKind;
use App\Modules\Gateways\Exceptions\GatewayConfigurationException;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\CaptureAuthorizedPayment;
use App\Modules\Payments\Actions\SyncPaymentAttempt;
use App\Modules\Payments\Data\CaptureResult;
use App\Modules\Payments\Enums\CaptureOutcome;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\SyncReason;
use App\Modules\Payments\Exceptions\AttemptBusyException;
use App\Modules\Payments\Models\PaymentAttempt;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * The payer's browser finished the gateway's next action (3D Secure, plan
 * 11.4): the link's current attempt is re-read from the gateway and, when
 * authorized, completed (merchant validation hook, capture; ADR-0050). The
 * page is told what happened. A failed or abandoned verification leaves the
 * link payable with another card.
 */
final readonly class CompleteCheckoutAuthorization
{
    public function __construct(
        private SyncPaymentAttempt $sync,
        private CaptureAuthorizedPayment $capture,
        private Repository $cache,
    ) {}

    /**
     * @param  string  $attemptId  the attempt this payer's session was handed the next action of
     */
    public function handle(PaymentLink $link, string $attemptId): CheckoutResult
    {
        $attempt = PaymentAttempt::query()->where('payment_link_id', $link->id)->whereKey($attemptId)->first();

        if ($attempt === null) {
            return CheckoutResult::of(CheckoutOutcome::Error);
        }

        // Debounce per attempt: a repeated call within the interval only
        // reads our own state; the gateway is asked once.
        if (! $this->cache->add('checkout:continue:'.$attempt->id, 1, max(1, config()->integer('axispay.checkout.status_sync_after_seconds')))) {
            return CheckoutResult::of(CheckoutOutcome::Processing);
        }

        try {
            $attempt = $this->sync->handle($attempt->id, SyncReason::Checkout, complete: false);
        } catch (GatewayException $e) {
            Log::warning('The checkout could not re-read a payment after 3D Secure.', ['payment_attempt_id' => $attempt->id, 'exception' => $e::class]);

            return CheckoutResult::of(CheckoutOutcome::Processing);
        }

        if ($attempt->status === PaymentAttemptStatus::RequiresCapture) {
            return self::complete($this->capture, $attempt->id);
        }

        return CheckoutResult::of(match ($attempt->status) {
            PaymentAttemptStatus::Succeeded => CheckoutOutcome::Paid,
            PaymentAttemptStatus::Processing, PaymentAttemptStatus::RequiresAction => CheckoutOutcome::Processing,
            PaymentAttemptStatus::RequiresPaymentMethod => $attempt->last_failure_kind === ProviderFailureKind::AuthenticationFailed
                ? CheckoutOutcome::AuthenticationFailed
                : CheckoutOutcome::Declined,
            default => $link->refresh()->status === PaymentLinkStatus::Paid ? CheckoutOutcome::Paid : CheckoutOutcome::Error,
        });
    }

    /**
     * Completes an authorization for the page. Never an error page: anything
     * unexpected answers "processing", and the webhook or the reconciliation
     * finishes the payment with the merchant's kept decision.
     */
    public static function complete(CaptureAuthorizedPayment $capture, string $attemptId, ?string $leaseToken = null): CheckoutResult
    {
        try {
            return self::toResult($capture->handle($attemptId, $leaseToken));
        } catch (GatewayException|GatewayConfigurationException|AttemptBusyException $e) {
            Log::warning('An authorization could not be completed now; left to the webhook and the reconciliation.', ['payment_attempt_id' => $attemptId, 'exception' => $e::class]);

            return CheckoutResult::of(CheckoutOutcome::Processing);
        }
    }

    /** What the page shows for the completion of an authorization. */
    public static function toResult(CaptureResult $result): CheckoutResult
    {
        return match ($result->outcome) {
            CaptureOutcome::Captured => CheckoutResult::of($result->attempt->status === PaymentAttemptStatus::Succeeded ? CheckoutOutcome::Paid : CheckoutOutcome::Processing),
            CaptureOutcome::Rejected => new CheckoutResult(CheckoutOutcome::MerchantRejected, payerMessage: $result->payerMessage),
            CaptureOutcome::Pending => CheckoutResult::of(CheckoutOutcome::Processing),
            CaptureOutcome::NotAuthorized => CheckoutResult::of($result->attempt->status === PaymentAttemptStatus::Succeeded ? CheckoutOutcome::Paid : CheckoutOutcome::Error),
        };
    }
}
