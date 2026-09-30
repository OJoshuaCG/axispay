<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Enums;

/**
 * Platform permission catalog (plan 17.4). Platform policies check these,
 * never role names (ADR-014). Platform roles are a column on platform_admins,
 * not spatie roles, so the catalog and each role's permissions live in code
 * (PlatformRole::permissions()), the platform counterpart of the tenant
 * catalog seeded by PermissionCatalogSeeder.
 */
enum PlatformPermission: string
{
    /** The platform logo and how the brand is shown (ADR-0053). */
    case BrandingManage = 'platform:branding:manage';

    /** The platform's privacy notice and terms, on the pay host's /legal page (ADR-0056). */
    case LegalManage = 'platform:legal:manage';
}
