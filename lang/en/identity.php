<?php

declare(strict_types=1);

/*
 * Identity: tenant users, invitations, re-authentication.
 */
return [

    'navigation' => [
        'group' => 'Team',
    ],

    'users' => [
        'singular' => 'user',
        'plural' => 'users',
        'empty' => [
            'heading' => 'No users yet',
            'description' => 'Use “Invite user” to add people to your team.',
        ],
        'fields' => [
            'name' => 'Name',
            'email' => 'E-mail',
            'role' => 'Role',
            'roles' => 'Roles',
            'two_factor' => '2FA',
            'status' => 'Status',
            'last_login_at' => 'Last sign-in',
            'disabled_at' => 'Deactivated',
        ],
        'status' => [
            'active' => 'Active',
            'disabled' => 'Deactivated',
        ],
        'actions' => [
            'invite' => 'Invite user',
            'change_roles' => 'Change roles',
            'deactivate' => 'Deactivate',
            'deactivate_confirm' => 'The user will no longer be able to sign in. You can reactivate them later.',
            'reactivate' => 'Reactivate',
        ],
        'notifications' => [
            'invited' => 'Invitation sent',
            'roles_changed' => 'Roles updated',
            'deactivated' => 'User deactivated',
            'reactivated' => 'User reactivated',
        ],
        'errors' => [
            'email_not_available' => 'This e-mail address cannot be invited.',
            'invitation_not_allowed' => 'The invitation could not be sent',
            'invitation_throttled' => 'Too many invitations. Try again later.',
            'role_change' => 'The roles could not be changed',
            'deactivate' => 'This user cannot be deactivated. A tenant must keep at least one active owner, and you cannot deactivate yourself.',
        ],
    ],

    'invitations' => [
        'status' => [
            'pending' => 'Pending',
            'accepted' => 'Accepted',
            'expired' => 'Expired',
            'revoked' => 'Revoked',
        ],
    ],

    'login' => [
        'throttled' => 'Too many failed sign-in attempts for this account. Try again in :minutes minutes.',
    ],

    'reauthentication' => [
        'field' => 'Your password or 2FA code',
        'help' => 'Confirm your identity to continue. You will not be asked again for 10 minutes.',
        'failed' => 'The password or code is not correct.',
        'throttled' => 'Too many attempts. Try again in :seconds seconds.',
        'required' => 'Enter your password or a 2FA code to confirm it is you.',
    ],

    'invitation' => [
        'title' => 'Join your team',
        'intro' => 'You were invited with the address :email. Choose your name and a password to create your account.',
        'name' => 'Full name',
        'password' => 'Password',
        'password_hint' => 'Avoid passwords you use elsewhere.',
        'password_confirmation' => 'Confirm password',
        'submit' => 'Create account',
        'error_title' => 'Please check the form',
        'error_body' => 'Some fields need your attention.',
        'invalid_title' => 'This invitation is no longer valid',
        'invalid_body' => 'The link has expired, was already used or was replaced by a newer invitation. Ask your team administrator to invite you again.',
    ],

    'password' => [
        // Shared by <x-password-input> and PasswordField (ADR-0046).
        'show' => 'Show password',
        'hide' => 'Hide password',
        'requirements' => [
            'title' => 'Password requirements',
            'min_length' => 'At least :count characters',
            'uncompromised' => 'Not found in known data leaks',
            'met' => 'Met',
            'not_met' => 'Not met yet',
            'on_submit' => 'Checked when you submit',
        ],
        'guidance' => 'Tip: a passphrase of a few unrelated words is long and easy to remember.',
        'mismatch' => 'The passwords do not match.',
    ],

    'mail' => [
        'invitation' => [
            'subject' => 'You have been invited',
            'line' => 'You have been invited to join :tenant with the role :role.',
            'action' => 'Accept invitation',
            'expires' => 'This invitation expires in :hours hours and can be used only once.',
            'ignore' => 'If you were not expecting this invitation, you can ignore this e-mail.',
        ],
    ],

];
