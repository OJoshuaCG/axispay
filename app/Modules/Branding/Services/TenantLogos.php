<?php

declare(strict_types=1);

namespace App\Modules\Branding\Services;

use App\Modules\Branding\Enums\LogoVariant;
use App\Modules\Branding\Models\TenantLogo;

/**
 * The merchant's logo (ADR-0056 part B), read through the tenant scope (the
 * checkout and the panel both run in the tenant's context). The merchant
 * "has a logo" when the light variant exists: a dark variant alone is never
 * shown.
 *
 * Scoped (one instance per request or job): a tenant's logos are read once,
 * without their bytes, and remembered while a page renders; the Branding
 * actions call forget().
 */
final class TenantLogos
{
    /** @var array<string, array<string, TenantLogo>> tenant => variant => logo (metadata only) */
    private array $logos = [];

    /**
     * @return array<string, TenantLogo> variant => logo, without `content`
     */
    public function all(string $tenantId): array
    {
        if (! array_key_exists($tenantId, $this->logos)) {
            $found = [];

            foreach (TenantLogo::query()->select(TenantLogo::METADATA_COLUMNS)->where('tenant_id', $tenantId)->get() as $logo) {
                $found[$logo->variant->value] = $logo;
            }

            $this->logos[$tenantId] = $found;
        }

        return $this->logos[$tenantId];
    }

    public function find(string $tenantId, LogoVariant $variant): ?TenantLogo
    {
        return $this->all($tenantId)[$variant->value] ?? null;
    }

    public function hasLogo(string $tenantId): bool
    {
        return $this->find($tenantId, LogoVariant::Light) !== null;
    }

    /** The stored PNG bytes of one variant (the settings page preview). */
    public function content(string $tenantId, LogoVariant $variant): ?string
    {
        $content = TenantLogo::query()->where('tenant_id', $tenantId)->where('variant', $variant->value)->value('content');

        return is_string($content) ? $content : null;
    }

    public function forget(string $tenantId): void
    {
        unset($this->logos[$tenantId]);
    }
}
