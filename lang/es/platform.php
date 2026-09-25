<?php

declare(strict_types=1);

/*
 * Keys mirror lang/en/platform.php.
 */
return [

    'role' => [
        'superadmin' => 'Superadministrador',
        'support_readonly' => 'Soporte (solo lectura)',
    ],

    'tenants' => [
        'singular' => 'cliente',
        'plural' => 'clientes',
        'fields' => [
            'id' => 'ID',
            'legal_name' => 'Razón social',
            'display_name' => 'Nombre comercial',
            'status' => 'Estado',
            'new_status' => 'Nuevo estado',
            'status_reason' => 'Motivo',
            'status_changed_at' => 'Cambio de estado',
            'timezone' => 'Zona horaria',
            'default_locale' => 'Idioma predeterminado',
            'support_email' => 'Correo de soporte',
            'owner_email' => 'Correo del propietario',
            'owner_email_help' => 'Opcional. Recibirá una invitación para unirse como propietario.',
            'created_at' => 'Creado',
            'close_confirmation' => 'Escriba «:name» para confirmar el cierre de este cliente',
            'status_help' => 'Los clientes nunca se eliminan: la auditoría sigue haciendo referencia a ellos. Para retirar un cliente, cambie su estado a Cerrado.',
        ],
        'actions' => [
            'change_status' => 'Cambiar estado',
        ],
        'notifications' => [
            'status_changed' => 'Estado modificado',
        ],
        'errors' => [
            'status_change' => 'No fue posible cambiar el estado. Revise la transición y la confirmación.',
        ],
        'invitations' => [
            'title' => 'Invitaciones',
            'singular' => 'invitación',
            'plural' => 'invitaciones',
            'empty' => 'Aún no hay invitaciones',
            'fields' => [
                'email' => 'Correo electrónico',
                'role' => 'Rol',
                'status' => 'Estado',
                'invited_at' => 'Invitado',
                'expires_at' => 'Vence',
            ],
            'actions' => [
                'invite_owner' => 'Invitar propietario',
                'invite_owner_help' => 'Envía una invitación para unirse a este cliente como propietario. Después, el propietario invita al resto del equipo desde el panel del cliente. Si hay una invitación pendiente para la misma dirección, se reemplaza.',
                'resend' => 'Reenviar',
                'resend_confirm' => 'Se enviará por correo un enlace nuevo válido por 72 horas. El enlace anterior deja de funcionar de inmediato.',
                'revoke' => 'Revocar',
                'revoke_confirm' => 'El enlace de la invitación dejará de funcionar de inmediato. No se puede deshacer; puede enviar una invitación nueva más adelante.',
            ],
            'notifications' => [
                'invited' => 'Invitación enviada',
                'resent' => 'Invitación reenviada',
                'revoked' => 'Invitación revocada',
            ],
            'errors' => [
                'not_pending' => 'Esta invitación ya fue aceptada o revocada.',
                'email_not_available' => 'No es posible invitar a esta dirección de correo.',
                'throttled' => 'Demasiadas invitaciones para este cliente. Inténtelo más tarde.',
                'not_allowed' => 'No fue posible enviar la invitación.',
            ],
        ],
        'users' => [
            'title' => 'Usuarios',
            'singular' => 'usuario',
            'plural' => 'usuarios',
            'empty' => 'Aún no hay usuarios. Invite a un propietario para dar acceso al cliente.',
        ],
    ],

    'admins' => [
        'singular' => 'administrador de la plataforma',
        'plural' => 'administradores de la plataforma',
        'fields' => [
            'name' => 'Nombre',
            'email' => 'Correo electrónico',
            'role' => 'Rol',
            'two_factor' => '2FA',
            'last_login_at' => 'Último acceso',
        ],
    ],

    'impersonation' => [
        'action' => 'Ver como usuario',
        'description' => 'Abre el panel del cliente como este usuario durante un máximo de 30 minutos, en solo lectura. La sesión queda registrada en la auditoría de la plataforma y del cliente.',
        'user' => 'Usuario',
        'reason' => 'Motivo',
        'banner' => 'Está viendo el panel como :name (solo lectura). La sesión termina a las :time.',
        'stop' => 'Dejar de ver como usuario',
        'errors' => [
            'not_allowed' => 'No es posible suplantar a este usuario.',
        ],
    ],

];
