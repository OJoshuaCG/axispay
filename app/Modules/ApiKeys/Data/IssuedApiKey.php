<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Data;

use App\Modules\ApiKeys\Models\ApiKey;
use SensitiveParameter;

/**
 * Result of CreateApiKey: the stored key and its plaintext, which the panel
 * shows exactly once and never persists.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $apiKey,
        #[SensitiveParameter] public string $plaintext,
    ) {}
}
