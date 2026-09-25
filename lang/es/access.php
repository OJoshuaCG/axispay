<?php

declare(strict_types=1);

/*
 * Keys mirror lang/en/access.php.
 */
return [

    'permission' => [
        'links_create' => 'Crear links',
        'links_read' => 'Ver links',
        'links_cancel' => 'Cancelar links',
        'payments_read' => 'Ver pagos',
        'payments_refund' => 'Reembolsar pagos',
        'metrics_read' => 'Ver métricas',
        'reports_export' => 'Exportar reportes',
        'api_keys_manage' => 'Gestionar llaves de API',
        'webhooks_manage' => 'Gestionar webhooks',
        'gateway_manage' => 'Gestionar la pasarela de pago',
        'settings_manage' => 'Gestionar la configuración',
        'users_manage' => 'Gestionar usuarios',
        'audit_read' => 'Ver el registro de auditoría',
    ],

    'role' => [
        'owner' => 'Propietario',
        'admin' => 'Administrador',
        'integration_manager' => 'Responsable de integración',
        'finance' => 'Finanzas',
        'link_creator' => 'Creador de links',
        'viewer' => 'Consulta',
    ],

    'roles' => [
        'singular' => 'rol',
        'plural' => 'roles',
        'empty' => [
            'heading' => 'No hay roles',
            'description' => 'Aquí aparecen los roles que puede asignar a su equipo.',
        ],
        'fields' => [
            'name' => 'Rol',
            'permissions' => 'Permisos',
            'permissions_count' => 'Permisos',
            'type' => 'Tipo',
        ],
        'type' => [
            'system' => 'Del sistema',
            'custom' => 'Personalizado',
        ],
    ],

    'errors' => [
        'last_owner' => 'El último propietario activo no puede perder el rol de propietario.',
        'exceeds_permissions' => 'Solo puede otorgar o retirar roles cuyos permisos usted tenga.',
        'no_roles' => 'Seleccione al menos un rol.',
    ],

    'mail' => [
        'sensitive_role' => [
            'subject' => 'Se asignó un rol sensible',
            'line' => 'Se asignó el rol :role a un miembro de su equipo.',
            'review' => 'Si no esperaba este cambio, revise su equipo en el panel.',
        ],
    ],

];
