<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Data;

use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\Tenancy\Enums\ApiAccess;

/**
 * A key that authenticated, with what its tenant may do through the API
 * (never ApiAccess::None: such keys do not authenticate).
 */
final readonly class AuthenticatedApiKey
{
    public function __construct(
        public ApiKey $key,
        public ApiAccess $access,
    ) {}
}
