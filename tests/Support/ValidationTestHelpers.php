<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Data\ValidationTimeouts;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Webhooks\Enums\ValidationFailurePolicy;
use App\Modules\Webhooks\Models\ValidationCall;
use App\Modules\Webhooks\Models\ValidationEndpoint;
use App\Modules\Webhooks\Services\WebhookSigner;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared helpers of the pre-payment validation tests (plan 15.8).
 */
final class ValidationTestHelpers
{
    public const string URL = 'https://validate.merchant.example/axispay/validate';

    /**
     * An endpoint written directly (the actions are tested on their own).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function endpoint(Tenant|string $tenant, bool $livemode = false, array $attributes = []): ValidationEndpoint
    {
        return self::in($tenant, $livemode, static function () use ($livemode, $attributes): ValidationEndpoint {
            $endpoint = new ValidationEndpoint;
            $endpoint->forceFill([
                'livemode' => $livemode,
                'url' => self::URL,
                'secret' => app(WebhookSigner::class)->generateSecret(),
                'enabled_by_default' => false,
                'failure_policy' => ValidationFailurePolicy::FailClosed,
                ...$attributes,
            ])->save();

            return $endpoint->refresh();
        });
    }

    /**
     * A checkout scenario whose link asks for validation, with the merchant's
     * validation URL configured in the same mode.
     *
     * @param  array<string, mixed>  $endpoint
     * @return array{Tenant, PaymentLink, FakePaymentGateway, ValidationEndpoint}
     */
    public static function scenario(array $endpoint = []): array
    {
        FakeHostResolver::install();
        [$tenant, $link, $fake] = CheckoutTestHelpers::scenario(static fn ($factory) => $factory->state(['pre_payment_validation' => true]));

        return [$tenant, $link, $fake, self::endpoint($tenant, false, $endpoint)];
    }

    /**
     * Runs the application with the merchant validation timeout T and every
     * limit derived from it (ADR-0061), as the configuration files would
     * derive them. Tests that count seconds of a request budget, a lease or a
     * job timeout pin T = 5, the value those numbers were written for.
     */
    public static function pinValidationTimeout(int $seconds): void
    {
        $timeouts = ValidationTimeouts::fromSeconds($seconds);

        config([
            'axispay.pre_payment_validation.timeout_seconds' => $timeouts->validationSeconds,
            'axispay.pre_payment_validation.connect_timeout_seconds' => $timeouts->connectTimeoutSeconds(),
            'axispay.checkout.pre_payment_validation_seconds' => $timeouts->validationSeconds,
            'axispay.checkout.request_budget_seconds' => $timeouts->requestBudgetSeconds(),
            'axispay.checkout.confirmation_lease_seconds' => $timeouts->confirmationLeaseSeconds(),
            'queue.connections.database.retry_after' => $timeouts->queueRetryAfterSeconds(),
            'queue.connections.redis.retry_after' => $timeouts->queueRetryAfterSeconds(),
            'queue.connections.beanstalkd.retry_after' => $timeouts->queueRetryAfterSeconds(),
        ]);
    }

    /** An authorized attempt of the link, written directly (for the validator alone). */
    public static function authorizedAttempt(PaymentLink $link): PaymentAttempt
    {
        return CheckoutTestHelpers::inTenant($link, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus(PaymentAttemptStatus::RequiresCapture)->createOne([
            'payment_link_id' => $link->id,
            'gateway_connection_id' => CheckoutTestHelpers::connectionOf($link)->id,
            'card_brand' => 'visa',
            'card_country' => 'MX',
            'card_last4' => '4242',
        ]));
    }

    /**
     * @return list<ValidationCall>
     */
    public static function calls(Tenant|string $tenant, bool $livemode = false): array
    {
        return self::in($tenant, $livemode, static fn (): array => array_values(ValidationCall::query()->orderBy('id')->get()->all()));
    }

    public static function freshEndpoint(ValidationEndpoint $endpoint): ValidationEndpoint
    {
        return self::in($endpoint->tenant_id, $endpoint->livemode, static fn (): ValidationEndpoint => ValidationEndpoint::query()->findOrFail($endpoint->id));
    }

    /**
     * An HTTP fake answer of the merchant.
     *
     * @param  array<string, mixed>  $json
     */
    public static function answer(array $json, int $status = 200): PromiseInterface
    {
        return Http::response($json, $status, ['Content-Type' => 'application/json']);
    }

    /**
     * The validation requests the HTTP fake received, in order.
     *
     * @return list<Request>
     */
    public static function sentRequests(): array
    {
        return WebhookTestHelpers::sentRequests();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function in(Tenant|string $tenant, bool $livemode, Closure $callback): mixed
    {
        return app(TenantContext::class)->runAsTenant($tenant instanceof Tenant ? $tenant->id : $tenant, $livemode, $callback);
    }
}
