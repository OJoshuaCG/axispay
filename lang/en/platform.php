<?php

declare(strict_types=1);

/*
 * Platform (superadmin) panel.
 */
return [

    'role' => [
        'superadmin' => 'Superadmin',
        'support_readonly' => 'Support (read-only)',
    ],

    'tenants' => [
        'singular' => 'tenant',
        'plural' => 'tenants',
        'fields' => [
            'id' => 'ID',
            'legal_name' => 'Legal name',
            'display_name' => 'Display name',
            'status' => 'Status',
            'new_status' => 'New status',
            'status_reason' => 'Reason',
            'status_changed_at' => 'Status changed',
            'timezone' => 'Time zone',
            'default_locale' => 'Default language',
            'support_email' => 'Support e-mail',
            'owner_email' => 'Owner e-mail',
            'owner_email_help' => 'Optional. Receives an invitation to join as owner.',
            'created_at' => 'Created',
            'close_confirmation' => 'Type ":name" to confirm closing this tenant',
            'status_help' => 'Tenants are never deleted: the audit trail keeps referring to them. Retire a tenant by changing its status to Closed.',
        ],
        'actions' => [
            'change_status' => 'Change status',
        ],
        'notifications' => [
            'status_changed' => 'Status changed',
        ],
        'errors' => [
            'status_change' => 'The status could not be changed. Check the transition and the confirmation.',
        ],
        'invitations' => [
            'title' => 'Invitations',
            'singular' => 'invitation',
            'plural' => 'invitations',
            'empty' => 'No invitations yet',
            'fields' => [
                'email' => 'E-mail',
                'role' => 'Role',
                'status' => 'Status',
                'invited_at' => 'Invited',
                'expires_at' => 'Expires',
            ],
            'actions' => [
                'invite_owner' => 'Invite owner',
                'invite_owner_help' => 'Sends an invitation to join this tenant as owner. The owner then invites the rest of the team from the tenant panel. A pending invitation for the same address is replaced.',
                'resend' => 'Resend',
                'resend_confirm' => 'A new link valid for 72 hours will be e-mailed. The previous link stops working immediately.',
                'revoke' => 'Revoke',
                'revoke_confirm' => 'The invitation link will stop working immediately. This cannot be undone; you can send a new invitation later.',
            ],
            'notifications' => [
                'invited' => 'Invitation sent',
                'resent' => 'Invitation resent',
                'revoked' => 'Invitation revoked',
            ],
            'errors' => [
                'not_pending' => 'This invitation was already accepted or revoked.',
                'email_not_available' => 'This e-mail address cannot be invited.',
                'throttled' => 'Too many invitations for this tenant. Try again later.',
                'not_allowed' => 'The invitation could not be sent.',
            ],
        ],
        'users' => [
            'title' => 'Users',
            'singular' => 'user',
            'plural' => 'users',
            'empty' => 'No users yet. Invite an owner to give the tenant access.',
        ],
    ],

    'admins' => [
        'singular' => 'platform admin',
        'plural' => 'platform admins',
        'fields' => [
            'name' => 'Name',
            'email' => 'E-mail',
            'role' => 'Role',
            'two_factor' => '2FA',
            'last_login_at' => 'Last sign-in',
        ],
    ],

    'impersonation' => [
        'action' => 'View as user',
        'description' => 'Opens the tenant panel as this user for up to 30 minutes, read-only. The session is recorded in the platform and tenant audit logs.',
        'user' => 'User',
        'reason' => 'Reason',
        'banner' => 'You are viewing the panel as :name (read-only). The session ends at :time.',
        'stop' => 'Stop viewing as user',
        'errors' => [
            'not_allowed' => 'This user cannot be impersonated.',
        ],
    ],

];
