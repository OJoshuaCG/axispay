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
        // Phase 2 (plan 7.4, 7.6). provider_events keeps NULL for unroutable
        // events (platform rows, AllowsPlatformRows).
        'gateway_connections',
        'provider_events',
        // Phase 3 (plan 7.2, 7.5, 7.8).
        'api_keys',
        'idempotency_records',
        'payment_links',
        // Phase 4 (plan 7.5, 9.2, 19.2; ADR-0051).
        'payment_attempts',
        'payment_attempt_failures',
        'payer_details',
        'domain_events',
        // ADR-0056: the merchant's privacy notice and terms.
        'tenant_legal_documents',
        // ADR-0056 part B: the merchant's logo (light and dark variants).
        'tenant_logos',
        // Phase 5 (plan 7.6, 15; ADR-0057): outgoing webhooks.
        'webhook_endpoints',
        'webhook_events',
        'webhook_deliveries',
        // Phase 5 (plan 7.4, 7.6, 15.8; ADR-0058): pre-payment validation.
        'validation_endpoints',
        'validation_calls',
        // Phase 6 (plan 7.5, 13.4; ADR-0063): the immutable FX quotes.
        'fx_quotes',
        // ADR-0064: the secret that signs the return to the merchant.
        'return_signing_secrets',
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
        // Phase 2 (ADR-0047): retention purge of provider_events across tenants.
        'App\\Modules\\ProviderEvents\\Services\\ProviderEventRetention',
        // ADR-0050: read-only webhook activity (last event per mode and per
        // connection) for axispay:doctor and the platform panel.
        'App\\Modules\\ProviderEvents\\Services\\ProviderEventActivity',
        // Phase 3 (ADR-0048): expiry purge of idempotency_records across tenants.
        'App\\Modules\\ApiKeys\\Services\\IdempotencyRecordRetention',
        // Phase 4 (ADR-0051): the reconciliation finds, across tenants, the
        // (tenant, mode) pairs with attempts to re-sync (identifiers only).
        'App\\Modules\\Payments\\Services\\PaymentAttemptLookup',
        // ADR-0051: recovery of stored gateway events (stuck, unroutable,
        // failed) across tenants; acts on each row in its own tenant context.
        'App\\Modules\\ProviderEvents\\Services\\ProviderEventInbox',
        // Phase 5 (ADR-0057): the outbox sweeper finds, across tenants, the
        // unpublished domain events and the due webhook deliveries
        // (identifiers only); each row is then handled in its tenant context.
        'App\\Modules\\Webhooks\\Services\\WebhookOutboxLookup',
        // Phase 5 (ADR-0058): deletes pre-payment validation calls of every
        // tenant past their 30-day retention (by age only, never reads them).
        'App\\Modules\\Webhooks\\Services\\ValidationCallRetention',
    ],

];
