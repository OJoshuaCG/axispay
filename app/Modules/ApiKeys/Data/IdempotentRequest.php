<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Data;

/**
 * A POST that carries an `Idempotency-Key` (plan 7.8, 10.3). `bodyHash` is
 * its RequestFingerprint.
 */
final readonly class IdempotentRequest
{
    public function __construct(
        public string $apiKeyId,
        public string $key,
        public string $method,
        public string $path,
        public string $bodyHash,
    ) {}
}
