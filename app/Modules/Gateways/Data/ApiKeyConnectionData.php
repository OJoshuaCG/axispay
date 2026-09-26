<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Data;

/**
 * Input of ConnectWithApiKey / UpdateApiKeyCredentials (plan 12.3.3).
 * `acceptExcessivePermissions` is the extra confirmation live mode requires
 * when the key can do more than we need (plan 12.3.3 step 6).
 */
final readonly class ApiKeyConnectionData
{
    public function __construct(
        public ApiKeyCredentials $credentials,
        public bool $riskAcknowledged,
        public bool $acceptExcessivePermissions = false,
    ) {}
}
