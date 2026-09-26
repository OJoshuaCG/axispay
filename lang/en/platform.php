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
        'sections' => [
            'profile' => 'Profile',
        ],
        'empty' => [
            'heading' => 'No tenants yet',
            'description' => 'Create a tenant to give a business its own panel, then invite its owner.',
        ],
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
            'owner_email_help' => 'Receives an invitation to join as owner. Every tenant needs one. It cannot belong to an existing user: e-mails are unique across the platform.',
            'owner' => 'Owner',
            'created_at' => 'Created',
            'close_confirmation' => 'Type ":name" to confirm closing this tenant',
            'status_help' => 'Tenants are never deleted: the audit trail keeps referring to them. Retire a tenant by changing its status to Closed.',
        ],
        'filters' => [
            'without_active_owner' => 'No active owner',
        ],
        'actions' => [
            'change_status' => 'Change status',
        ],
        'notifications' => [
            'status_changed' => 'Status changed',
        ],
        'errors' => [
            'status_change' => 'The status could not be changed. Check the transition and the confirmation.',
            'owner_email_taken' => 'This e-mail already belongs to a user. E-mails are unique across the platform and a user belongs to one tenant only, so use another address for the owner.',
        ],
        'ownership' => [
            'state' => [
                'active' => 'Active',
                'pending_invitation' => 'Pending invitation',
                'none' => 'None',
            ],
            'callout' => [
                'heading' => 'This tenant has no active owner',
                'body' => 'Nobody can connect the payment gateway or manage the owners until an owner joins.',
                'no_invitation' => 'No owner invitation is pending.',
                'pending_invitation' => 'An owner invitation to :email is pending until :date.',
                'expired_invitation' => 'The last owner invitation, to :email, expired on :date.',
                'recover' => 'Invite an owner or resend the invitation. If the person is already a user of this tenant, use "Make owner" in the Users list.',
            ],
            'actions' => [
                'resend' => 'Resend owner invitation',
            ],
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
            'fields' => [
                'promotion_reason' => 'Reason',
                'promotion_reason_help' => 'At least 10 characters. Recorded in the platform and tenant audit logs; not included in the e-mails.',
            ],
            'actions' => [
                'promote_owner' => 'Make owner',
                'promote_owner_heading' => 'Make this user an owner',
                'promote_owner_help' => 'Adds the owner role to this user; their other roles are kept. Owners have full access, including the payment gateway connection. The current owners and the user are notified by e-mail.',
                'promote_owner_submit' => 'Make owner',
            ],
            'notifications' => [
                'promoted' => 'The user is now an owner',
            ],
            'errors' => [
                'reason_required' => 'Enter a reason of at least 10 characters.',
                'tenant_closed' => 'The owner role cannot be granted in a closed tenant.',
                'inactive_user' => 'Only an active user can become an owner.',
                'already_owner' => 'This user is already an owner.',
                'reauthentication' => 'Confirm your password or 2FA code to continue.',
            ],
        ],
    ],

    'admins' => [
        'singular' => 'platform admin',
        'plural' => 'platform admins',
        'navigation' => 'Admins',
        'empty' => [
            'heading' => 'No platform admins',
            'description' => 'Platform admins are created from the console with axispay:create-platform-admin.',
        ],
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
        'row_action' => 'View as this user',
        'row_heading' => 'View the panel as :name',
        'no_active_users' => 'This tenant has no active users yet. Invite an owner first.',
        'errors' => [
            'not_allowed' => 'This user cannot be impersonated.',
        ],
    ],

];
