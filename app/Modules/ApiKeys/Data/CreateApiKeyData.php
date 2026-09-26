<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Data;

use App\Modules\ApiKeys\Enums\ApiScope;

/**
 * Input of CreateApiKey. The mode is the current one of the panel (the
 * tenant context), never chosen here.
 */
final readonly class CreateApiKeyData
{
    /**
     * @param  list<ApiScope>  $scopes
     */
    public function __construct(
        public string $name,
        public array $scopes,
    ) {}
}
