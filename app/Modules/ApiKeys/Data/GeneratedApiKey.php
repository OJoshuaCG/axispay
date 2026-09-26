<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Data;

use SensitiveParameter;

/**
 * A freshly generated key: the plaintext exists only in memory, to be shown
 * once; the rest is what gets stored.
 */
final readonly class GeneratedApiKey
{
    public function __construct(
        #[SensitiveParameter] public string $plaintext,
        public string $hash,
        public string $prefix,
        public string $last4,
    ) {}
}
