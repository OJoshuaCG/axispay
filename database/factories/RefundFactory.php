<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Payments\Enums\RefundOrigin;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\RefundState;
use App\Modules\Payments\Models\Refund;
use App\Modules\Shared\Money\CurrencyCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a tenant context, with `payment_attempt_id` given. Writes the
 * state directly, which only factories may do: code goes through
 * ApplyProviderRefund.
 *
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount_minor' => 50_000,
            'currency' => CurrencyCode::USD,
            'status' => RefundState::Pending,
            'reason' => RefundReason::Other,
            'origin' => RefundOrigin::Api,
            'created_by_actor_type' => ActorType::System->value,
        ];
    }
}
