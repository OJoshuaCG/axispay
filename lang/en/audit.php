<?php

declare(strict_types=1);

/*
 * Audit log (plan 7.1).
 */
return [

    'singular' => 'audit entry',
    'plural' => 'audit log',
    'empty' => [
        'heading' => 'No audit entries yet',
        'description' => 'Sign-ins, role changes and other sensitive actions are recorded here.',
    ],
    'platform' => 'Platform',

    'fields' => [
        'created_at' => 'Date',
        'action' => 'Action',
        'actor' => 'Actor',
        'actor_id' => 'Actor ID',
        'subject' => 'Subject',
        'subject_id' => 'Subject ID',
        'changes' => 'Details',
        'ip' => 'IP address',
        'user_agent' => 'User agent',
        'request_id' => 'Request ID',
        'tenant' => 'Tenant',
    ],

    'filters' => [
        'scope' => 'Scope',
        'platform_only' => 'Platform events',
        'tenants_only' => 'Tenant events',
    ],

    'actor_type' => [
        'user' => 'User',
        'platform_admin' => 'Platform admin',
        'api_key' => 'API key',
        'system' => 'System',
    ],

    'action' => [
        'auth_login' => 'Signed in',
        'auth_login_failed' => 'Failed sign-in',
        'auth_login_throttled' => 'Sign-in throttled',
        'auth_logout' => 'Signed out',
        'two_factor_enabled' => '2FA enabled',
        'two_factor_disabled' => '2FA disabled',
        'two_factor_recovery_codes_regenerated' => '2FA recovery codes regenerated',
        'two_factor_reset' => '2FA reset by an operator',
        'password_reset' => 'Password reset by an operator',
        'reauthentication_confirmed' => 'Identity confirmed',
        'reauthentication_failed' => 'Identity confirmation failed',
        'invitation_created' => 'Invitation sent',
        'invitation_accepted' => 'Invitation accepted',
        'invitation_revoked' => 'Invitation revoked',
        'invitation_resent' => 'Invitation resent',
        'invitation_refused' => 'Invitation refused',
        'user_deactivated' => 'User deactivated',
        'user_reactivated' => 'User reactivated',
        'role_assigned' => 'Role assigned',
        'role_revoked' => 'Role removed',
        'tenant_created' => 'Tenant created',
        'tenant_updated' => 'Tenant profile updated',
        'tenant_status_changed' => 'Tenant status changed',
        'impersonation_started' => 'Impersonation started',
        'impersonation_ended' => 'Impersonation ended',
        'platform_context_entered' => 'Platform context entered',
        'livemode_switched' => 'Test/live mode switched',
        'platform_admin_created' => 'Platform admin created',
    ],

];
