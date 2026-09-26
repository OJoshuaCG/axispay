<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * Outcome of validating a merchant key pair (plan 12.3.3): the account the
 * keys belong to and the permission report stored in
 * `validated_permissions` (Stripe permission names, no key material).
 */
final readonly class ApiKeyValidationResult
{
    /**
     * @param  list<string>  $granted
     * @param  list<string>  $excessive
     */
    public function __construct(
        public ConnectedAccountData $account,
        public array $granted,
        public array $excessive,
    ) {}

    /**
     * @return array{checked_at: string, granted: list<string>, missing: list<string>, excessive: list<string>}
     */
    public function toReport(): array
    {
        return [
            'checked_at' => now()->toIso8601String(),
            'granted' => $this->granted,
            'missing' => [],
            'excessive' => $this->excessive,
        ];
    }
}
