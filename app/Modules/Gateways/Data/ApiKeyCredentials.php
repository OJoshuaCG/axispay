<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

use LogicException;
use SensitiveParameter;

/**
 * A merchant key pair typed in the panel (api_key method). Lives in memory
 * only: it refuses serialization and hides the secret from var_dump/dd and
 * from exception traces (SensitiveParameter).
 */
final readonly class ApiKeyCredentials
{
    public function __construct(
        #[SensitiveParameter] public string $restrictedKey,
        public string $publishableKey,
    ) {}

    public static function from(#[SensitiveParameter] string $restrictedKey, string $publishableKey): self
    {
        return new self(trim($restrictedKey), trim($publishableKey));
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->restrictedKey);
    }

    public function last4(): string
    {
        return substr($this->restrictedKey, -4);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['restrictedKey' => '[REDACTED]', 'publishableKey' => $this->publishableKey];
    }

    public function __serialize(): array
    {
        throw new LogicException('Merchant credentials must never be serialized.');
    }
}
