<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Multi-tenancy (plan section 6, ADR-002)
|--------------------------------------------------------------------------
|
| This file is the SINGLE source of truth for which tables hold tenant data.
| It is read by the PHPStan rule that forbids raw access to those tables
| (Tests\PHPStan\Rules\TenantTableAccessRule, plan 6.5) and by the isolation
| tests (tests/Feature/Tenancy), which fail when a table with a `tenant_id`
| column is missing here or a model on such a table lacks BelongsToTenant.
|
*/

return [

    /*
     * Tables with a `tenant_id` column. Their models use BelongsToTenant
     * (fail-closed global scope). Raw SQL / DB::table() on them is forbidden.
     */
    'tenant_tables' => [
        'users',
        'user_invitations',
        'audit_logs',
        'impersonation_sessions',
    ],

    /*
     * spatie/laravel-permission tables scoped by `team_id` (team = tenant,
     * plan 7.2). The package owns their queries; application code must not
     * touch them with raw SQL either.
     */
    'team_scoped_tables' => [
        'roles',
        'model_has_roles',
        'model_has_permissions',
    ],

    /*
     * Classes allowed to call withoutGlobalScope(s) (plan 6.5). A namespace
     * entry ends with a backslash and allows every class inside it. Adding an
     * entry needs a justification in docs/adr/0031-tenancy-enforcement.md.
     */
    'scope_bypass_whitelist' => [
        // Plan 6.5 (later phases).
        'App\\Modules\\PaymentLinks\\Services\\PaymentLinkLookup',
        'App\\Modules\\ApiKeys\\Services\\ApiKeyAuthenticator',
        'App\\Modules\\Gateways\\Services\\GatewayConnectionResolver',
        'App\\Modules\\Gateways\\Services\\OAuthStateStore',
        'App\\Modules\\PlatformAdmin\\',
        'App\\Modules\\Reporting\\',
        // Phase 1: authentication entry points that run before a tenant is known.
        'App\\Modules\\Identity\\Auth\\TenantUserProvider',
        'App\\Modules\\Identity\\Services\\InvitationLookup',
        'App\\Modules\\Identity\\Services\\UserDirectory',
    ],

];
