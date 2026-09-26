<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\PayerFields\Enums\PayerField;
use App\Modules\PaymentLinks\Enums\CreatedVia;
use App\Modules\PaymentLinks\Enums\PaymentLinkStatus;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Shared\Ids\SecureToken;
use App\Modules\Shared\Money\CurrencyCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a tenant context (TenantContext::runAsTenant). States follow
 * plan 26.3 (`->expired()`, `->canceled()`, `->paid()`...). They write the
 * status directly, which only factories may do: code goes through
 * PaymentLinkStateMachine.
 *
 * @extends Factory<PaymentLink>
 */
class PaymentLinkFactory extends Factory
{
    protected $model = PaymentLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payerFields = [];

        foreach (PayerField::cases() as $field) {
            $payerFields[$field->value] = $field->platformDefault()->value;
        }

        return [
            'public_token' => SecureToken::base62(),
            'status' => PaymentLinkStatus::Active,
            'amount_minor' => 150_000,
            'currency' => CurrencyCode::USD,
            'description' => 'Order #'.fake()->numerify('A-####'),
            'payer_fields_config' => $payerFields,
            'locale' => 'es',
            'expires_at' => now()->addDays(7),
            'created_via' => CreatedVia::Api,
            'created_by_actor_type' => ActorType::System,
        ];
    }

    /** A link in the given status, with the matching timestamps. */
    public function inStatus(PaymentLinkStatus $status): static
    {
        return match ($status) {
            PaymentLinkStatus::Active => $this,
            PaymentLinkStatus::Processing => $this->processing(),
            PaymentLinkStatus::Paid => $this->paid(),
            PaymentLinkStatus::Expired => $this->expired(),
            PaymentLinkStatus::Canceled => $this->canceled(),
        };
    }

    public function pastExpiry(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['status' => PaymentLinkStatus::Expired, 'expires_at' => now()->subHour(), 'expired_at' => now()->subHour()]);
    }

    public function canceled(): static
    {
        return $this->state(fn (): array => ['status' => PaymentLinkStatus::Canceled, 'canceled_at' => now()]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => ['status' => PaymentLinkStatus::Paid, 'paid_at' => now()]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => ['status' => PaymentLinkStatus::Processing]);
    }
}
