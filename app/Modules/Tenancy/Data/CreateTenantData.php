<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Data;

/**
 * Input of CreateTenant. `ownerEmail` is required (plan 17.2, ADR-0045): it
 * receives an owner invitation so every new tenant starts with a way to get
 * its first owner. It must not belong to an existing user (e-mails are
 * unique across the platform).
 */
final readonly class CreateTenantData
{
    public function __construct(
        public string $legalName,
        public string $displayName,
        public string $ownerEmail,
        public string $timezone = 'America/Mexico_City',
        public string $defaultLocale = 'es',
        public ?string $supportEmail = null,
    ) {}
}
