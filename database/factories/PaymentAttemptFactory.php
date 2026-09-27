<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Shared\Money\CurrencyCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a tenant context, with `payment_link_id` and
 * `gateway_connection_id` given. States write the status directly, which
 * only factories may do: code goes through PaymentAttemptStateMachine.
 *
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_fake_'.bin2hex(random_bytes(8)),
            'status' => PaymentAttemptStatus::RequiresPaymentMethod,
            'amount_minor' => 150_000,
            'currency' => CurrencyCode::USD,
            'original_amount_minor' => 150_000,
            'original_currency' => CurrencyCode::USD,
        ];
    }

    public function inStatus(PaymentAttemptStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
