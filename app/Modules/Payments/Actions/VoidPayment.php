<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\VoidReason;
use App\Modules\Payments\Exceptions\AttemptBusyException;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Http\Errors\ApiErrorCode;
use App\Modules\Shared\Http\Errors\ApiException;
use Illuminate\Support\Facades\Log;

/**
 * `POST /v1/payments/{id}/void` (spec B10, ADR-0066): the integrator releases
 * an authorization that was not captured yet. No money moves and nothing is
 * refunded; the payer's bank releases the hold. It is VoidAuthorization with
 * the reason `merchant_requested`, so it shares the attempt's lease with the
 * capture and never races it:
 *
 *  - already canceled: the same answer (a retry);
 *  - not an authorization waiting for capture (`requires_capture`): 409
 *    `payment_not_voidable`; a captured payment is refunded instead;
 *  - another process holds the payment (the capture, the pre-payment
 *    validation, the reconciliation): 409 `payment_busy`, retry in seconds;
 *  - the capture won the race before the void reached the gateway: the
 *    payment succeeded, so 409 `payment_not_voidable` too;
 *  - the gateway could not be reached: 502 `gateway_error`, nothing changed,
 *    retry with the same request.
 *
 * `payment.canceled` with the reason is recorded by ApplyProviderPayment. The
 * link goes back to `active` like after any release: whether to close it is
 * the integrator's own decision (cancel the link).
 */
final readonly class VoidPayment
{
    public function __construct(private VoidAuthorization $void) {}

    /**
     * @throws ApiException
     */
    public function handle(string $attemptId): PaymentAttempt
    {
        $attempt = PaymentAttempt::query()->find($attemptId) ?? throw ApiException::of(ApiErrorCode::ResourceNotFound, 'No such payment.');

        if ($attempt->status === PaymentAttemptStatus::Canceled) {
            return $attempt;
        }

        if ($attempt->status !== PaymentAttemptStatus::RequiresCapture) {
            throw self::notVoidable($attempt->status);
        }

        try {
            $voided = $this->void->handle($attempt->id, VoidReason::MerchantRequested);
        } catch (AttemptBusyException) {
            throw ApiException::of(ApiErrorCode::PaymentBusy);
        } catch (GatewayException $e) {
            Log::warning('The gateway could not be reached to void an authorization.', ['payment_attempt_id' => $attempt->id, 'exception' => $e::class]);

            throw ApiException::of(ApiErrorCode::GatewayError);
        }

        if ($voided->status !== PaymentAttemptStatus::Canceled) {
            throw self::notVoidable($voided->status);
        }

        return $voided;
    }

    private static function notVoidable(PaymentAttemptStatus $status): ApiException
    {
        return ApiException::of(
            ApiErrorCode::PaymentNotVoidable,
            $status === PaymentAttemptStatus::Succeeded
                ? 'The payment was already captured: refund it instead.'
                : "The payment is {$status->value}, not an authorization waiting for capture.",
        );
    }
}
