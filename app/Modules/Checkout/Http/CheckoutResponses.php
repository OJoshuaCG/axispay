<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http;

use App\Modules\Checkout\Data\CheckoutResult;
use App\Modules\Checkout\Enums\CheckoutOutcome;
use App\Modules\Checkout\Services\CheckoutUrls;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Tenancy\Services\TenantAccess;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Presentation of the checkout answers (plan 11.5): the JSON the page script
 * reads and the uniform 404 page. Messages are translated here, so the
 * script carries no copy. Declines are always the generic message (plan
 * 11.7 rule 7); nothing about the card or the gateway is echoed.
 */
final readonly class CheckoutResponses
{
    public function __construct(
        private CheckoutUrls $urls,
        private ViewFactory $views,
        private TenantAccess $access,
    ) {}

    public function result(CheckoutResult $result, PaymentLink $link): JsonResponse
    {
        $merchant = $this->access->displayName($link->tenant_id);

        $message = match ($result->outcome) {
            CheckoutOutcome::Declined => __('checkout.messages.declined'),
            CheckoutOutcome::AuthenticationFailed => __('checkout.messages.authentication_failed'),
            CheckoutOutcome::TurnstileRequired => __('checkout.messages.turnstile'),
            CheckoutOutcome::RateLimited => trans_choice('checkout.messages.rate_limited', $result->minutes ?? 1, ['minutes' => $result->minutes ?? 1]),
            CheckoutOutcome::Blocked, CheckoutOutcome::Unavailable => __('checkout.messages.unavailable', ['merchant' => $merchant]),
            CheckoutOutcome::Error => __('checkout.messages.error'),
            default => null,
        };

        // A card-testing pause: the page (and any client) waits the real time left.
        $headers = $result->outcome === CheckoutOutcome::RateLimited ? ['Retry-After' => (string) (($result->minutes ?? 1) * 60)] : [];

        return new JsonResponse(array_filter([
            'outcome' => $result->outcome->value,
            'message' => $message,
            'client_secret' => $result->clientSecret,
            'turnstile_required' => $result->turnstileRequired || $result->outcome === CheckoutOutcome::TurnstileRequired,
            'retry_after_minutes' => $result->minutes,
            'payer_message' => $result->payerMessage,
            'redirect_url' => $result->outcome->leavesForm() ? $this->urls->complete($link) : null,
        ], static fn (mixed $value): bool => $value !== null), $result->outcome->httpStatus(), $headers);
    }

    /**
     * @param  array<string, string>  $errors
     */
    public function invalidFields(array $errors): JsonResponse
    {
        return new JsonResponse(['outcome' => 'invalid_fields', 'message' => __('checkout.messages.fix_fields'), 'errors' => $errors], 422);
    }

    /** Plan 11.2: the same page and status for every invalid token. */
    public function notFound(bool $json = false): Response|JsonResponse
    {
        if ($json) {
            return new JsonResponse(['outcome' => 'not_found'], 404);
        }

        return new Response($this->views->make('checkout.not-found')->render(), 404);
    }
}
