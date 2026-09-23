<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

/**
 * Input of CreateTenant. `ownerEmail`, when given, receives an owner
 * invitation so the tenant's first user can join.
 */
final readonly class CreateTenantData
{
    public function __construct(
        public string $legalName,
        public string $displayName,
        public string $timezone = 'America/Mexico_City',
        public string $defaultLocale = 'es',
        public ?string $supportEmail = null,
        public ?string $ownerEmail = null,
    ) {}
}
