<?php

declare(strict_types=1);

/*
 * Keys mirror lang/en/identity.php.
 */
return [

    'navigation' => [
        'group' => 'Equipo',
    ],

    'users' => [
        'singular' => 'usuario',
        'plural' => 'usuarios',
        'fields' => [
            'name' => 'Nombre',
            'email' => 'Correo electrónico',
            'role' => 'Rol',
            'roles' => 'Roles',
            'two_factor' => '2FA',
            'status' => 'Estado',
            'last_login_at' => 'Último acceso',
            'disabled_at' => 'Desactivado',
        ],
        'status' => [
            'active' => 'Activo',
            'disabled' => 'Desactivado',
        ],
        'actions' => [
            'invite' => 'Invitar usuario',
            'change_roles' => 'Cambiar roles',
            'deactivate' => 'Desactivar',
            'deactivate_confirm' => 'El usuario ya no podrá iniciar sesión. Puede reactivarlo más adelante.',
            'reactivate' => 'Reactivar',
        ],
        'notifications' => [
            'invited' => 'Invitación enviada',
            'roles_changed' => 'Roles actualizados',
            'deactivated' => 'Usuario desactivado',
            'reactivated' => 'Usuario reactivado',
        ],
        'errors' => [
            'email_not_available' => 'No es posible invitar a esta dirección de correo.',
            'invitation_not_allowed' => 'No fue posible enviar la invitación',
            'invitation_throttled' => 'Demasiadas invitaciones. Inténtelo más tarde.',
            'role_change' => 'No fue posible cambiar los roles',
            'deactivate' => 'No es posible desactivar a este usuario. Cada cuenta debe conservar al menos un propietario activo y usted no puede desactivarse a sí mismo.',
        ],
    ],

    'login' => [
        'throttled' => 'Demasiados intentos fallidos para esta cuenta. Inténtelo de nuevo en :minutes minutos.',
    ],

    'reauthentication' => [
        'field' => 'Su contraseña o código 2FA',
        'help' => 'Confirme su identidad para continuar. No se le volverá a pedir durante 10 minutos.',
        'failed' => 'La contraseña o el código no son correctos.',
        'throttled' => 'Demasiados intentos. Inténtelo de nuevo en :seconds segundos.',
    ],

    'invitation' => [
        'title' => 'Únase a su equipo',
        'intro' => 'Recibió una invitación en la dirección :email. Indique su nombre y una contraseña para crear su cuenta.',
        'name' => 'Nombre completo',
        'password' => 'Contraseña',
        'password_hint' => 'Al menos 12 caracteres. Evite contraseñas que use en otros sitios.',
        'password_confirmation' => 'Confirmar contraseña',
        'submit' => 'Crear cuenta',
        'error_title' => 'Revise el formulario',
        'error_body' => 'Algunos campos requieren su atención.',
        'invalid_title' => 'Esta invitación ya no es válida',
        'invalid_body' => 'El enlace venció, ya se utilizó o fue reemplazado por una invitación más reciente. Pida al administrador de su equipo que lo invite de nuevo.',
    ],

    'mail' => [
        'invitation' => [
            'subject' => 'Ha recibido una invitación',
            'line' => 'Lo invitaron a unirse a :tenant con el rol :role.',
            'action' => 'Aceptar invitación',
            'expires' => 'Esta invitación vence en :hours horas y solo puede usarse una vez.',
            'ignore' => 'Si no esperaba esta invitación, puede ignorar este correo.',
        ],
    ],

];
