<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'legal_name' => $name.' S.A. de C.V.',
            'display_name' => $name,
            'status' => TenantStatus::Active,
            'timezone' => 'America/Mexico_City',
            'default_locale' => 'es',
        ];
    }

    public function status(TenantStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
