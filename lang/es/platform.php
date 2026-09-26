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
        'sections' => [
            'profile' => 'Perfil',
        ],
        'empty' => [
            'heading' => 'Aún no hay clientes',
            'description' => 'Cree un cliente para dar a una empresa su propio panel y después invite a su propietario.',
        ],
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
            'owner_email_help' => 'Recibirá una invitación para unirse como propietario. Todo cliente necesita uno. No puede pertenecer a un usuario existente: los correos son únicos en toda la plataforma.',
            'owner' => 'Propietario',
            'created_at' => 'Creado',
            'close_confirmation' => 'Escriba «:name» para confirmar el cierre de este cliente',
            'status_help' => 'Los clientes nunca se eliminan: la auditoría sigue haciendo referencia a ellos. Para retirar un cliente, cambie su estado a Cerrado.',
        ],
        'filters' => [
            'without_active_owner' => 'Sin propietario activo',
        ],
        'actions' => [
            'change_status' => 'Cambiar estado',
        ],
        'notifications' => [
            'status_changed' => 'Estado modificado',
        ],
        'errors' => [
            'status_change' => 'No fue posible cambiar el estado. Revise la transición y la confirmación.',
            'owner_email_taken' => 'Este correo ya pertenece a un usuario. Los correos son únicos en toda la plataforma y cada usuario pertenece a un solo cliente, así que use otra dirección para el propietario.',
        ],
        'ownership' => [
            'state' => [
                'active' => 'Activo',
                'pending_invitation' => 'Invitación pendiente',
                'none' => 'Ninguno',
            ],
            'callout' => [
                'heading' => 'Este cliente no tiene un propietario activo',
                'body' => 'Nadie puede conectar la pasarela de pagos ni administrar a los propietarios hasta que se una un propietario.',
                'no_invitation' => 'No hay ninguna invitación de propietario pendiente.',
                'pending_invitation' => 'Hay una invitación de propietario para :email pendiente hasta el :date.',
                'expired_invitation' => 'La última invitación de propietario, para :email, venció el :date.',
                'recover' => 'Invite a un propietario o reenvíe la invitación. Si la persona ya es usuaria de este cliente, use «Hacer propietario» en la lista de usuarios.',
            ],
            'actions' => [
                'resend' => 'Reenviar invitación de propietario',
            ],
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
            'fields' => [
                'promotion_reason' => 'Motivo',
                'promotion_reason_help' => 'Al menos 10 caracteres. Queda registrado en la auditoría de la plataforma y del cliente; no se incluye en los correos.',
            ],
            'actions' => [
                'promote_owner' => 'Hacer propietario',
                'promote_owner_heading' => 'Hacer propietario a este usuario',
                'promote_owner_help' => 'Agrega el rol de propietario a este usuario y conserva sus demás roles. Los propietarios tienen acceso completo, incluida la conexión con la pasarela de pagos. Los propietarios actuales y el usuario reciben un aviso por correo.',
                'promote_owner_submit' => 'Hacer propietario',
            ],
            'notifications' => [
                'promoted' => 'El usuario ahora es propietario',
            ],
            'errors' => [
                'reason_required' => 'Escriba un motivo de al menos 10 caracteres.',
                'tenant_closed' => 'No se puede otorgar el rol de propietario en un cliente cerrado.',
                'inactive_user' => 'Solo un usuario activo puede ser propietario.',
                'already_owner' => 'Este usuario ya es propietario.',
                'reauthentication' => 'Confirme su contraseña o su código 2FA para continuar.',
            ],
        ],
    ],

    'admins' => [
        'singular' => 'administrador de la plataforma',
        'plural' => 'administradores de la plataforma',
        'navigation' => 'Administradores',
        'empty' => [
            'heading' => 'No hay administradores de la plataforma',
            'description' => 'Los administradores de la plataforma se crean desde la consola con axispay:create-platform-admin.',
        ],
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
        'row_action' => 'Ver como este usuario',
        'row_heading' => 'Ver el panel como :name',
        'no_active_users' => 'Este cliente todavía no tiene usuarios activos. Invite primero a un propietario.',
        'errors' => [
            'not_allowed' => 'No es posible suplantar a este usuario.',
        ],
    ],

];
