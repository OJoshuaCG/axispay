<?php

declare(strict_types=1);

/*
 * Keys mirror lang/en/audit.php.
 */
return [

    'singular' => 'entrada de auditoría',
    'plural' => 'registro de auditoría',
    'platform' => 'Plataforma',

    'fields' => [
        'created_at' => 'Fecha',
        'action' => 'Acción',
        'actor' => 'Actor',
        'actor_id' => 'ID del actor',
        'subject' => 'Objeto',
        'subject_id' => 'ID del objeto',
        'changes' => 'Detalles',
        'ip' => 'Dirección IP',
        'user_agent' => 'Agente de usuario',
        'request_id' => 'ID de solicitud',
        'tenant' => 'Cliente',
    ],

    'filters' => [
        'scope' => 'Ámbito',
        'platform_only' => 'Eventos de la plataforma',
        'tenants_only' => 'Eventos de clientes',
    ],

    'actor_type' => [
        'user' => 'Usuario',
        'platform_admin' => 'Administrador de la plataforma',
        'api_key' => 'Llave de API',
        'system' => 'Sistema',
    ],

    'action' => [
        'auth_login' => 'Inicio de sesión',
        'auth_login_failed' => 'Inicio de sesión fallido',
        'auth_login_throttled' => 'Inicio de sesión bloqueado temporalmente',
        'auth_logout' => 'Cierre de sesión',
        'two_factor_enabled' => '2FA activado',
        'two_factor_disabled' => '2FA desactivado',
        'two_factor_recovery_codes_regenerated' => 'Códigos de recuperación de 2FA regenerados',
        'two_factor_reset' => '2FA restablecido por un operador',
        'password_reset' => 'Contraseña restablecida por un operador',
        'reauthentication_confirmed' => 'Identidad confirmada',
        'reauthentication_failed' => 'Confirmación de identidad fallida',
        'invitation_created' => 'Invitación enviada',
        'invitation_accepted' => 'Invitación aceptada',
        'invitation_revoked' => 'Invitación revocada',
        'invitation_resent' => 'Invitación reenviada',
        'invitation_refused' => 'Invitación rechazada',
        'user_deactivated' => 'Usuario desactivado',
        'user_reactivated' => 'Usuario reactivado',
        'role_assigned' => 'Rol asignado',
        'role_revoked' => 'Rol retirado',
        'tenant_created' => 'Cliente creado',
        'tenant_updated' => 'Perfil del cliente modificado',
        'tenant_status_changed' => 'Estado del cliente modificado',
        'impersonation_started' => 'Suplantación iniciada',
        'impersonation_ended' => 'Suplantación finalizada',
        'platform_context_entered' => 'Acceso en contexto de plataforma',
        'livemode_switched' => 'Cambio de modo de prueba/real',
        'platform_admin_created' => 'Administrador de la plataforma creado',
    ],

];
