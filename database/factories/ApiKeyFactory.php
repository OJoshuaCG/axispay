<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\ApiKeys\Enums\ApiScope;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\ApiKeys\Services\ApiKeyGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a tenant context (TenantContext::runAsTenant); `tenant_id`
 * and `livemode` come from the context. withPlaintext() stores the hash of a
 * known key so tests can authenticate with it.
 *
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generated = app(ApiKeyGenerator::class)->generate(false);

        return [
            'name' => 'Integration '.fake()->word(),
            'key_prefix' => $generated->prefix,
            'key_last4' => $generated->last4,
            'key_hash' => $generated->hash,
            'scopes' => ApiScope::values(),
        ];
    }

    public function withPlaintext(string $plaintext): static
    {
        return $this->state(fn (): array => [
            'key_prefix' => substr($plaintext, 0, 13),
            'key_last4' => substr($plaintext, -4),
            'key_hash' => ApiKeyGenerator::hash($plaintext),
        ]);
    }

    /**
     * @param  list<ApiScope>  $scopes
     */
    public function scopes(array $scopes): static
    {
        return $this->state(fn (): array => ['scopes' => array_map(static fn (ApiScope $scope): string => $scope->value, $scopes)]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
