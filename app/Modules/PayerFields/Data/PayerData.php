<?php

declare(strict_types=1);

namespace App\Modules\PayerFields\Data;

use LogicException;
use SensitiveParameter;

/**
 * Validated payer data of one attempt (plan 19.1): only the fields the link
 * collects, normalized (trimmed, phone in E.164). Personal data: stored
 * encrypted (PayerDetails), never logged, never serialized into jobs.
 */
final readonly class PayerData
{
    /**
     * @param  array<string, string|array<string, string>>  $values  field => value (billing_address => parts)
     */
    public function __construct(#[SensitiveParameter] public array $values) {}

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function email(): ?string
    {
        $value = $this->values['email'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, string|array<string, string>> */
    public function toArray(): array
    {
        return $this->values;
    }

    public function __debugInfo(): array
    {
        return ['fields' => array_keys($this->values)];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Payer data must never be serialized.');
    }
}
