<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Services;

/**
 * The `Idempotency-Key` of the current request and its body fingerprint,
 * set by EnforceIdempotency once the request owns the key. Scoped: reset for
 * every request and job.
 */
final class CurrentIdempotentRequest
{
    private ?string $key = null;

    private ?string $bodyHash = null;

    public function set(string $key, string $bodyHash): void
    {
        $this->key = $key;
        $this->bodyHash = $bodyHash;
    }

    public function key(): ?string
    {
        return $this->key;
    }

    public function bodyHash(): ?string
    {
        return $this->bodyHash;
    }
}
