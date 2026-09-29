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
        'owner_promoted' => 'Owner role granted by the platform',
        'gateway_onboarding_started' => 'Stripe onboarding started',
        'gateway_connected' => 'Stripe connected',
        'gateway_status_changed' => 'Stripe connection status changed',
        'gateway_disconnected' => 'Stripe disconnected',
        'gateway_risk_acknowledged' => 'API key risk notice accepted',
        'gateway_credentials_updated' => 'Stripe API keys updated',
        'gateway_credentials_rejected' => 'Stripe API keys rejected',
        'gateway_credentials_invalid' => 'Stripe API keys no longer valid',
        'api_key_created' => 'API key created',
        'api_key_revoked' => 'API key revoked',
        'payment_link_created' => 'Payment link created',
        'payment_link_canceled' => 'Payment link canceled',
        'payment_link_checkout_blocked' => 'Payment link blocked for card testing',
        'payment_link_checkout_unblocked' => 'Payment link unblocked',
        'payment_late_succeeded' => 'Payment succeeded after the link closed',
        'payment_authorization_voided' => 'Card authorization voided',
        'payment_needs_review' => 'Payment flagged for review',
        'provider_event_failed' => 'Gateway event failed',
        'provider_event_retried' => 'Gateway event retried',
        'platform_logo_updated' => 'Platform logo updated',
        'platform_logo_removed' => 'Platform logo removed',
        'platform_brand_display_mode_changed' => 'Platform brand display changed',
        'platform_favicon_updated' => 'Platform favicon updated',
        'platform_favicon_removed' => 'Platform favicon removed',
    ],

];
