<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

/**
 * Input of UpdateTenantProfile: the tenant's profile fields, validated like
 * on creation. Status is not part of it (ChangeTenantStatus, plan 21.3).
 */
final readonly class UpdateTenantProfileData
{
    public function __construct(
        public string $legalName,
        public string $displayName,
        public string $timezone,
        public string $defaultLocale,
        public ?string $supportEmail = null,
    ) {}

    /**
     * @return array{legal_name: string, display_name: string, timezone: string, default_locale: string, support_email: string|null}
     */
    public function attributes(): array
    {
        return [
            'legal_name' => trim($this->legalName),
            'display_name' => trim($this->displayName),
            'timezone' => $this->timezone,
            'default_locale' => $this->defaultLocale,
            'support_email' => $this->supportEmail !== null && trim($this->supportEmail) !== '' ? trim($this->supportEmail) : null,
        ];
    }
}
