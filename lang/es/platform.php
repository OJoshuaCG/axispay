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
