<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Contracts;

/**
 * Marks a tenant-scoped model whose `tenant_id` may be NULL for platform-level
 * rows (for example platform audit events). A NULL tenant is only kept when it
 * is set explicitly; an absent `tenant_id` is still filled from the context.
 * Platform rows are never visible through the tenant scope.
 */
interface AllowsPlatformRows {}
