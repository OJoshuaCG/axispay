<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers;

use App\Modules\Checkout\Actions\CompleteCheckoutAuthorization;
use App\Modules\Checkout\Actions\StartCheckoutPayment;
use App\Modules\Checkout\Data\CheckoutPaymentInput;
use App\Modules\Checkout\Data\CheckoutResult;
use App\Modules\Checkout\Enums\CheckoutOutcome;
use App\Modules\Checkout\Http\CheckoutResponses;
use App\Modules\Checkout\Http\CheckoutSession;
use App\Modules\Checkout\Services\CheckoutLinkResolver;
use App\Modules\Checkout\Services\CheckoutLocale;
use App\Modules\PayerFields\Exceptions\InvalidPayerDataException;
use App\Modules\PaymentLinks\Models\PaymentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /l/{token}/attempts (pay) and POST /l/{token}/attempts/continue
 * (after 3D Secure), plan 11.5. CSRF-protected by the anonymous checkout
 * session. Presentation only: the flow lives in StartCheckoutPayment and
 * CompleteCheckoutAuthorization. The request body is never logged.
 */
final readonly class CheckoutAttemptController
{
    public function __construct(
        private CheckoutLinkResolver $resolver,
        private CheckoutResponses $responses,
    ) {}

    public function store(Request $request, string $token, StartCheckoutPayment $start): JsonResponse|Response
    {
        $link = $this->resolver->resolve($token);

        if ($link === null) {
            return $this->responses->notFound(json: true);
        }

        CheckoutLocale::apply($request, $link->locale);
        $payer = $request->input('payer');
        $confirmationToken = $request->input('confirmation_token');
        $turnstile = $request->input('turnstile_token');

        try {
            $result = $start->handle($link, new CheckoutPaymentInput(
                confirmationToken: is_string($confirmationToken) ? $confirmationToken : '',
                payer: is_array($payer) ? $payer : [],
                turnstileToken: is_string($turnstile) ? $turnstile : null,
                clientIp: $request->ip(),
                userAgent: $request->userAgent(),
                sessionDeclines: CheckoutSession::declines($request, $link->id),
            ));
        } catch (InvalidPayerDataException $e) {
            return $this->responses->invalidFields($e->errors);
        }

        return $this->answer($request, $link, $result);
    }

    public function continue(Request $request, string $token, CompleteCheckoutAuthorization $complete): JsonResponse|Response
    {
        $link = $this->resolver->resolve($token);

        if ($link === null) {
            return $this->responses->notFound(json: true);
        }

        CheckoutLocale::apply($request, $link->locale);

        // Only the session that was handed the 3D Secure step may continue
        // it (ADR-0051): anyone else is told a payment is in progress.
        $attemptId = CheckoutSession::takeNextAction($request, $link->id);

        if ($attemptId === null) {
            return $this->responses->result(CheckoutResult::of(CheckoutOutcome::InProgress), $link);
        }

        return $this->answer($request, $link, $complete->handle($link, $attemptId));
    }

    private function answer(Request $request, PaymentLink $link, CheckoutResult $result): JsonResponse
    {
        if ($result->declined || in_array($result->outcome, [CheckoutOutcome::Declined, CheckoutOutcome::AuthenticationFailed], true)) {
            CheckoutSession::recordDecline($request, $link->id);
        }

        if ($result->outcome === CheckoutOutcome::RequiresAction && $result->attemptId !== null) {
            CheckoutSession::awaitNextAction($request, $link->id, $result->attemptId);
        }

        // This session started the payment: the completion page says
        // "Payment complete" once the link is paid (also after polling).
        if (in_array($result->outcome, [CheckoutOutcome::Paid, CheckoutOutcome::Processing, CheckoutOutcome::RequiresAction], true)) {
            CheckoutSession::markPaying($request, $link->id);
        }

        return $this->responses->result($result, $link);
    }
}
