<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Services;

use App\Modules\ApiKeys\Data\AuthenticatedApiKey;
use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\Tenancy\Enums\ApiAccess;
use App\Modules\Tenancy\Services\TenantAccess;
use SensitiveParameter;

/**
 * Entry point of the API surface (plan 6.3): finds the key by its SHA-256
 * before any tenant is known, so it reads `api_keys` without the tenant
 * scope. On the scope-bypass whitelist (config/tenancy.php, ADR-0031).
 *
 * Every failure returns null, so callers answer the same
 * `401 invalid_api_key` whether the key never existed, was revoked, expired,
 * names the wrong mode, or its tenant has no API access any more (TenantAccess,
 * plan 21.3). The stored hash is compared in constant time.
 */
final readonly class ApiKeyAuthenticator
{
    public function __construct(private TenantAccess $tenants) {}

    public function authenticate(#[SensitiveParameter] string $candidate): ?AuthenticatedApiKey
    {
        $livemode = ApiKeyGenerator::modeOf($candidate);

        if ($livemode === null) {
            return null;
        }

        $hash = ApiKeyGenerator::hash($candidate);

        $key = ApiKey::query()
            ->withoutGlobalScopes()
            ->where('key_hash', $hash)
            ->first();

        if ($key === null || ! hash_equals($key->key_hash, $hash)) {
            return null;
        }

        // The prefix decides the mode (plan 10.2): a key stored for the other
        // mode never authenticates, whatever its hash.
        if ($key->livemode !== $livemode || ! $key->isUsable()) {
            return null;
        }

        $access = $this->tenants->apiAccess($key->tenant_id);

        return $access === ApiAccess::None ? null : new AuthenticatedApiKey($key, $access);
    }
}
