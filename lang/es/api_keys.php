<?php

declare(strict_types=1);

/*
 * Llaves de API (plan 10.2, 17.3).
 */
return [

    'singular' => 'llave de API',
    'plural' => 'llaves de API',

    'navigation' => [
        'group' => 'Configuración',
    ],

    'page' => [
        'subheading' => [
            'test' => 'Llaves del modo de prueba. Solo alcanzan datos de prueba y nunca mueven dinero real.',
            'live' => 'Llaves del modo real. Crean links de pago reales: guárdelas solo en su servidor.',
        ],
    ],

    'fields' => [
        'summary' => 'Llave',
        'name' => 'Nombre',
        'name_help' => 'Dónde se usa la llave, por ejemplo "Tienda en línea".',
        'key' => 'Llave',
        'scopes' => 'Permisos',
        'scopes_help' => 'Dé a la llave solo lo que la integración necesita.',
        'status' => 'Estado',
        'last_used_at' => 'Último uso',
        'created_at' => 'Creada',
    ],

    'never_used' => 'Nunca',
    'scope_all' => 'Todos los permisos',

    'status' => [
        'active' => 'Activa',
        'revoked' => 'Revocada',
        'expired' => 'Vencida',
    ],

    'scope' => [
        'links_create' => 'Crear links de pago',
        'links_read' => 'Ver links de pago',
        'links_cancel' => 'Cancelar links de pago',
        'payments_read' => 'Ver pagos',
        'refunds_create' => 'Crear reembolsos',
        'refunds_read' => 'Ver reembolsos',
        'events_read' => 'Ver eventos',
    ],

    'filters' => [
        'status' => 'Estado',
        'active_only' => 'Llaves activas',
        'revoked_only' => 'Llaves revocadas',
        'all' => 'Todas las llaves',
    ],

    'empty' => [
        'heading' => 'Aún no hay llaves de API',
        'description' => 'Cree una llave para conectar su sistema con la API. Las llaves pertenecen a la cuenta, no a una persona.',
    ],

    'actions' => [
        'create' => 'Crear llave de API',
        'create_submit' => 'Crear llave',
        'create_help' => [
            'test' => 'La llave funcionará solo en modo de prueba.',
            'live' => 'La llave funcionará en modo real y podrá crear links de pago reales. Todos los propietarios recibirán un correo.',
        ],
        'revoke' => 'Revocar',
        'revoke_heading' => '¿Revocar esta llave de API?',
        'revoke_submit' => 'Revocar llave',
        'revoke_help' => 'Se revocará la llave «:name» (:key). Se usó por última vez :since: las integraciones que la usen fallarán de inmediato. No se puede deshacer.',
        'revoke_help_unused' => 'Se revocará la llave «:name» (:key). Nunca se ha usado. No se puede deshacer.',
        'revoke_live_note' => 'Se avisará por correo a los propietarios.',
    ],

    'issued' => [
        'heading' => 'Su nueva llave de API',
        'description' => 'Guárdela en la configuración de su servidor o en su gestor de secretos.',
        'warning_heading' => 'Cópiela ahora',
        'warning' => [
            'test' => 'Es la única vez que se muestra la llave completa. Si la pierde, revóquela y cree una nueva.',
            'live' => 'Es la única vez que se muestra la llave completa. Cualquiera con esta llave puede crear cobros reales: guárdela solo en su servidor. Si la pierde, revóquela y cree una nueva.',
        ],
        'copy' => 'Copiar llave',
        'copied' => 'Llave copiada',
        'copy_failed' => 'No se pudo copiar. Seleccione la llave y cópiela a mano.',
        'done' => 'Ya copié la llave',
    ],

    'notifications' => [
        'revoked' => 'Llave de API revocada',
        'created' => 'Llave «:name» creada (:key)',
    ],

    'validation' => [
        'name_required' => 'Dé un nombre a la llave.',
        'scopes_required' => 'Elija al menos un permiso.',
    ],

    'errors' => [
        'tenant_read_only' => 'Su cuenta está en solo lectura en su estado actual: no se pueden crear llaves de API nuevas. Aún puede revocar llaves.',
        'invalid_name' => 'Dé a la llave un nombre de hasta 100 caracteres.',
        'no_scopes' => 'Elija al menos un permiso.',
        'reauthentication_required' => 'Confirme su contraseña para continuar.',
    ],

    'mail' => [
        'live_revoked' => [
            'subject' => 'Se revocó una llave de API de modo real',
            'line' => ':user revocó la llave de API de modo real ":name". Las integraciones que aún la usen ahora fallarán.',
            'review' => 'Si no lo esperaba, revise Configuración → Llaves de API y el registro de auditoría.',
        ],
        'live_created' => [
            'subject' => 'Se creó una llave de API de modo real',
            'line' => ':user creó la llave de API de modo real ":name". Puede crear links de pago reales.',
            'review' => 'Si no lo reconoce, revoque la llave en Configuración → Llaves de API.',
        ],
    ],

];
