<?php

declare(strict_types=1);

/*
 * Access: permissions, system roles and role errors (plan 17).
 */
return [

    'permission' => [
        'links_create' => 'Create links',
        'links_read' => 'View links',
        'links_cancel' => 'Cancel links',
        'payments_read' => 'View payments',
        'payments_refund' => 'Refund payments',
        'metrics_read' => 'View metrics',
        'reports_export' => 'Export reports',
        'api_keys_manage' => 'Manage API keys',
        'webhooks_manage' => 'Manage webhooks',
        'gateway_manage' => 'Manage the payment gateway',
        'settings_manage' => 'Manage settings',
        'users_manage' => 'Manage users',
        'audit_read' => 'View the audit log',
    ],

    'role' => [
        'owner' => 'Owner',
        'admin' => 'Administrator',
        'integration_manager' => 'Integration manager',
        'finance' => 'Finance',
        'link_creator' => 'Link creator',
        'viewer' => 'Viewer',
    ],

    'roles' => [
        'singular' => 'role',
        'plural' => 'roles',
        'empty' => [
            'heading' => 'No roles',
            'description' => 'Roles you can assign to your team appear here.',
        ],
        'fields' => [
            'name' => 'Role',
            'permissions' => 'Permissions',
            'permissions_count' => 'Permissions',
            'type' => 'Type',
        ],
        'type' => [
            'system' => 'System',
            'custom' => 'Custom',
        ],
    ],

    'errors' => [
        'last_owner' => 'The last active owner cannot lose the owner role.',
        'exceeds_permissions' => 'You can only grant or remove roles whose permissions you hold yourself.',
        'no_roles' => 'Select at least one role.',
    ],

    'mail' => [
        'sensitive_role' => [
            'subject' => 'A sensitive role was assigned',
            'line' => 'The role :role was assigned to a member of your team.',
            'review' => 'If you did not expect this change, review your team in the panel.',
        ],
    ],

];
