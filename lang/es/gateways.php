<?php

declare(strict_types=1);

return [

    'navigation' => [
        'group' => 'Configuración',
    ],

    'provider' => [
        'stripe' => 'Stripe',
    ],

    'mode' => [
        'test' => 'modo de prueba',
        'live' => 'modo real',
    ],

    'method' => [
        'platform_onboarding' => 'Creada o conectada con Stripe',
        'oauth' => 'Conectada con OAuth',
        'api_key' => 'Sus llaves de API (avanzado)',
    ],

    'status' => [
        'onboarding' => 'En registro',
        'active' => 'Activa',
        'restricted' => 'Restringida',
        'invalid_credentials' => 'Llaves no válidas',
        'disconnected' => 'Desconectada',
    ],

    'disconnect_reason' => [
        'user_requested' => 'Desconectada por un usuario',
        'deauthorized' => 'Retirada desde la cuenta de Stripe',
    ],

    'page' => [
        'title' => 'Conexión con Stripe',
        'subheading' => [
            'test' => 'Está viendo el modo de prueba. Las conexiones de prueba y reales son independientes.',
            'live' => 'Está viendo el modo real. Los pagos reales llegan a esta cuenta.',
        ],
    ],

    'connect' => [
        'onboarding' => [
            'heading' => 'Crear o conectar con Stripe (recomendado)',
            'description' => 'Stripe le guía para crear su cuenta o conectar la que ya tiene. Stripe solicita los datos de su negocio y usted conserva el acceso completo a su panel de Stripe.',
        ],
        'api_key' => [
            'heading' => 'Avanzado: usar mis llaves de API',
            'description' => 'Para negocios que ya tienen una cuenta de Stripe verificada y prefieren darnos una llave restringida.',
            'warning_heading' => 'Guardaremos una credencial de su cuenta de Stripe',
            'warning' => 'Use esta opción solo si no puede usar la recomendada. Debe crear una llave restringida con los permisos mínimos y rotarla si sospecha que quedó expuesta.',
        ],
        'none_enabled' => 'No hay ningún método de conexión disponible por ahora. Contacte a soporte.',
    ],

    'details' => [
        'heading' => 'Detalles de la conexión',
        'excessive_heading' => 'Su llave puede hacer más de lo necesario',
        'excessive_body' => 'También tiene estos permisos: :permissions. Le recomendamos crear una llave restringida nueva sin ellos y actualizarla aquí.',
    ],

    'callout' => [
        'onboarding' => [
            'heading' => 'El registro no ha terminado',
            'body' => 'Aún no puede recibir pagos. Continúe el registro en Stripe; esta página se actualiza cuando Stripe confirma su cuenta.',
        ],
        'restricted' => [
            'heading' => 'Stripe pausó los pagos de esta cuenta',
            'body' => 'No se pueden crear links de pago hasta que Stripe vuelva a habilitar los cobros. Revise los requisitos pendientes abajo.',
        ],
        'invalid_credentials' => [
            'heading' => 'Sus llaves de API dejaron de funcionar',
            'body' => 'Stripe rechazó la llave guardada. Es posible que se haya revocado o cambiado. Actualice sus llaves para volver a recibir pagos.',
        ],
    ],

    'fields' => [
        'status' => 'Estado',
        'method' => 'Método de conexión',
        'account' => 'Cuenta de Stripe',
        'country' => 'País',
        'default_currency' => 'Moneda predeterminada',
        'charges_enabled' => 'Puede recibir pagos',
        'payouts_enabled' => 'Puede recibir transferencias',
        'last_synced_at' => 'Última actualización desde Stripe',
        'restricted_key' => 'Llave restringida',
        'last_health_check_at' => 'Última revisión de la llave',
        'yes' => 'Sí',
        'no' => 'No',
    ],

    'requirements' => [
        'heading' => 'Requisitos pendientes',
        'description' => 'Stripe le pide esta información cuando continúa el registro.',
        'intro' => 'Stripe aún necesita :count dato(s):',
        'deadline' => 'Stripe los solicita antes del :date.',
        'reason' => [
            'information_needed' => 'Stripe necesita más información para habilitar los pagos.',
            'rejected' => 'Stripe rechazó esta cuenta. Contacte al soporte de Stripe para conocer los detalles.',
            'under_review' => 'Stripe está revisando esta cuenta.',
            'paused' => 'Stripe pausó esta cuenta. Revise su panel de Stripe para conocer los detalles.',
        ],
    ],

    'actions' => [
        'start_onboarding' => 'Conectar con Stripe',
        'start_onboarding_help' => 'Continuará en el sitio de Stripe y volverá aquí al terminar.',
        'go_to_stripe' => 'Ir a Stripe',
        'country_help' => 'El país de su negocio. No se puede cambiar después.',
        'continue_onboarding' => 'Continuar el registro',
        'continue_onboarding_help' => 'Continuará en el sitio de Stripe donde se quedó.',
        'refresh' => 'Actualizar estado',
        'connect_api_key' => 'Conectar con llaves de API',
        'update_keys' => 'Actualizar llaves',
        'disconnect' => 'Desconectar',
        'disconnect_heading' => '¿Desconectar Stripe?',
        'disconnect_help' => [
            'platform_onboarding' => 'Los pagos se detienen en este modo. Su cuenta de Stripe sigue siendo suya; para volver a conectarla, inicie una conexión nueva.',
            'oauth' => 'Los pagos se detienen en este modo y la plataforma pierde el acceso a su cuenta de Stripe.',
            'api_key' => 'Los pagos se detienen en este modo. Eliminamos el webhook que creamos en su cuenta de Stripe y borramos sus llaves guardadas. También puede eliminar la llave restringida en Stripe.',
        ],
    ],

    // Ayuda del método api_key: permisos de la llave restringida y cómo
    // crearla. Las listas vienen de StripeKeyPermissions (ADR-0047).
    'permissions_help' => [
        'action' => 'Ver permisos necesarios',
        'heading' => 'Permisos de la llave restringida',
        'form_heading' => '¿Qué permisos necesita la llave?',
        'close' => 'Cerrar',
        'intro' => 'Cree en Stripe una llave restringida con exactamente estos permisos. Los comprobamos al guardar la llave.',
        'steps_heading' => 'Cómo crear la llave',
        'steps' => [
            '1' => 'En el Dashboard de Stripe, asegúrese de estar en el mismo modo que este panel (:mode).',
            '2' => 'Vaya a Desarrolladores → Claves de API → "Crear clave restringida".',
            '3' => 'Asígnele un nombre, por ejemplo "AxisPay".',
            '4' => 'Configure los permisos de la tabla siguiente y deje todo lo demás en "Ninguno".',
            '5' => 'Cree la llave y cópiela (empieza por rk_).',
            '6' => 'Copie también la llave publicable (pk_) de la misma cuenta y modo.',
            '7' => 'Pegue ambas llaves en el formulario.',
        ],
        'table_heading' => 'Permisos necesarios',
        'columns' => [
            'resource' => 'Recurso',
            'level' => 'Permiso',
            'why' => 'Para qué lo necesitamos',
            'identifier' => 'Nombre técnico',
        ],
        'level' => [
            'read' => 'Lectura',
            'write' => 'Escritura',
        ],
        'resources' => [
            'connected_account_read' => 'Cuentas',
            'token_read' => 'Tokens',
            'webhook_write' => 'Endpoints de webhooks',
            'payment_intent_write' => 'PaymentIntents',
            'charge_write' => 'Cargos y reembolsos',
            'charge_read' => 'Cargos y reembolsos',
            'dispute_read' => 'Disputas',
            'event_read' => 'Eventos',
            'payment_method_read' => 'PaymentMethods',
            'confirmation_token_read' => 'ConfirmationTokens',
            'payout_write' => 'Pagos a su banco (payouts)',
            'transfer_write' => 'Transferencias',
            'balance_read' => 'Saldo',
        ],
        'reasons' => [
            'connected_account_read' => 'Leer el país de su cuenta y si puede aceptar cobros.',
            'token_read' => 'Confirmar que ambas llaves son de la misma cuenta.',
            'webhook_write' => 'Crear el endpoint que nos avisa de los cambios en su cuenta.',
            'payment_intent_write' => 'Cobrar las tarjetas de sus clientes.',
            'charge_write' => 'Emitir reembolsos.',
            'charge_read' => 'Conciliar los pagos con Stripe.',
            'dispute_read' => 'Dar seguimiento a las disputas (contracargos).',
            'event_read' => 'Conciliar los pagos con Stripe.',
            'payment_method_read' => 'Conocer el país de la tarjeta para las reglas de moneda.',
            'confirmation_token_read' => 'Completar el pago con tarjeta de la página de pago.',
        ],
        'dangerous' => [
            'heading' => 'No otorgue estos permisos',
            'body' => 'Nunca los necesitamos y permiten mover o ver su dinero. Si la llave los tiene, le avisaremos, y en modo real deberá confirmar antes de que la usemos.',
        ],
        'labels_note' => 'Los nombres en el Dashboard de Stripe pueden variar un poco: identifique cada permiso por el nombre del recurso.',
        'docs_link' => 'Guía de Stripe sobre llaves restringidas',
        'new_tab' => '(se abre en una pestaña nueva)',
    ],

    'api_key' => [
        'help' => 'Pegue las llaves de su cuenta de Stripe para el :mode. Las encuentra en el panel de Stripe, en Desarrolladores > Claves de API.',
        'restricted_key' => 'Llave restringida',
        'restricted_key_help' => 'Empieza con rk_. Nunca pegue una llave secreta (sk_). Después de guardarla, solo se muestran sus últimos cuatro caracteres.',
        'publishable_key' => 'Llave publicable',
        'publishable_key_help' => 'Empieza con pk_ y pertenece a la misma cuenta y modo de Stripe.',
        'show_key' => 'Mostrar llave',
        'hide_key' => 'Ocultar llave',
        'accept_excessive' => 'Entiendo que esta llave también tiene estos permisos y quiero usarla de todos modos: :permissions',
        'risk' => [
            'heading' => 'Antes de continuar',
            'body' => 'La plataforma guardará cifrada una credencial de su cuenta de Stripe. Usted es responsable de dar a la llave restringida solo los permisos indicados en la guía y de rotarla si sospecha que quedó expuesta. Revisamos la llave todos los días y le avisamos si deja de funcionar.',
            'accept' => 'Acepto este aviso',
        ],
        'errors' => [
            'secret_key_not_allowed' => 'Nunca se aceptan llaves secretas (sk_): dan control total de su cuenta. Cree en Stripe una llave restringida (rk_) con los permisos de la guía.',
            'not_a_restricted_key' => 'Esta no es una llave restringida de Stripe. Debe empezar con rk_test_ o rk_live_.',
            'invalid_publishable_key' => 'Esta no es una llave publicable de Stripe. Debe empezar con pk_test_ o pk_live_.',
            'key_modes_differ' => 'La llave restringida y la llave publicable son de modos distintos (prueba y real).',
            'panel_mode_mismatch' => 'Estas llaves son del otro modo. Cambie el panel a ese modo o use las llaves de este modo.',
            'key_rejected' => 'Stripe rechazó la llave restringida. Verifique que la copió completa y que no se ha eliminado.',
            'account_not_readable' => 'La llave restringida no puede leer los datos de su cuenta. Dele permiso de lectura en Cuentas.',
            'country_not_allowed' => 'Su cuenta de Stripe está en un país que aún no admitimos (:details).',
            'publishable_key_rejected' => 'Stripe rechazó la llave publicable. Verifique que la copió completa.',
            'publishable_key_other_account' => 'La llave publicable pertenece a una cuenta de Stripe distinta de la de la llave restringida.',
            'missing_permissions' => 'A la llave restringida le faltan permisos: :details. Edite la llave en Stripe e inténtelo de nuevo.',
            'excessive_permissions_not_confirmed' => 'Esta llave también tiene permisos que no necesitamos (:details). Cree una llave más limitada o confirme abajo que quiere usarla.',
            'account_already_linked' => 'Esta cuenta de Stripe ya está conectada a otra cuenta de la plataforma.',
            'account_uses_connect' => 'Esta cuenta de Stripe está vinculada a la plataforma mediante Stripe Connect. No puede conectarse también con llaves de API.',
            'key_already_linked' => 'Esta llave ya está en uso. Cree una llave restringida nueva para esta conexión.',
            'different_account' => 'Estas llaves pertenecen a otra cuenta de Stripe. Para cambiar de cuenta, desconecte y vuelva a conectar.',
            'webhook_endpoint_failed' => 'No pudimos crear el webhook en su cuenta de Stripe, así que la conexión no se guardó. Inténtelo de nuevo.',
            'gateway_unavailable' => 'No pudimos comunicarnos con Stripe. No se guardó nada. Inténtelo de nuevo en unos minutos.',
        ],
    ],

    'errors' => [
        'method_disabled' => 'Este método de conexión no está disponible.',
        'already_connected' => 'Stripe ya está conectado en este modo. Desconéctelo primero para usar otro método.',
        'country_not_allowed' => 'Este país aún no está disponible.',
        'not_onboarding' => 'Esta conexión no tiene un registro pendiente.',
        'not_api_key' => 'Esta conexión no usa llaves de API.',
        'risk_not_acknowledged' => 'Debe aceptar el aviso de riesgo para continuar.',
        'gateway_unavailable' => 'No pudimos comunicarnos con Stripe. Inténtelo de nuevo en unos minutos.',
        'gateway_refused' => 'Stripe rechazó la solicitud. Inténtelo de nuevo o contacte a soporte.',
        'reauthentication_required' => 'Confirme su identidad para continuar.',
    ],

    'notifications' => [
        'refreshed' => 'Estado actualizado desde Stripe',
        'api_key_connected' => 'Stripe conectado con sus llaves de API',
        'api_key_updated' => 'Llaves de API actualizadas',
        'disconnected' => 'Stripe desconectado',
    ],

    'onboarding' => [
        'returned' => 'Bienvenido de nuevo. El estado de abajo es el que Stripe reporta en este momento; puede tardar unos minutos en actualizarse.',
        'sync_failed' => 'No pudimos leer su cuenta en Stripe. Use "Actualizar estado" en un momento.',
        'confirm_to_continue' => 'El enlace de Stripe expiró. Use "Continuar el registro" para obtener uno nuevo.',
    ],

    'mail' => [
        'footer' => 'Si no esperaba este cambio, revise los accesos de su equipo y su cuenta de Stripe.',
        'connected' => [
            'subject' => 'Stripe conectado (:mode)',
            'line' => 'Su cuenta de Stripe quedó conectada en :mode y puede recibir pagos.',
        ],
        'disconnected' => [
            'subject' => 'Stripe desconectado (:mode)',
            'line' => 'Su cuenta de Stripe se desconectó en :mode. Los pagos se detienen hasta que vuelva a conectarla.',
        ],
        'restricted' => [
            'subject' => 'Stripe pausó los pagos (:mode)',
            'line' => 'Stripe pausó los pagos de su cuenta en :mode. Revise los requisitos pendientes en el panel.',
        ],
        'invalid_credentials' => [
            'subject' => 'Sus llaves de API de Stripe dejaron de funcionar (:mode)',
            'line' => 'Stripe rechazó la llave de API guardada en :mode. Los links de pago nuevos quedan bloqueados hasta que actualice sus llaves.',
        ],
        'excessive_permissions' => [
            'subject' => 'Su llave de Stripe tiene más permisos de los necesarios (:mode)',
            'line' => 'La llave restringida conectada en :mode puede hacer más de lo que la plataforma necesita. Le recomendamos reemplazarla por una llave más limitada.',
        ],
    ],

    'admin' => [
        'heading' => 'Pasarela de pago',
        'empty' => 'Aún no hay conexión con la pasarela.',
        'mode' => 'Modo',
        'connected_at' => 'Conectada',
        'disconnected_at' => 'Desconectada',
        'last_event' => 'Último evento de Stripe',
        'last_event_value' => ':relative (:date UTC)',
        'no_events' => 'Aún no se ha recibido ninguno',
        'events_health' => 'Eventos de Stripe',
        'silent' => 'Sin eventos en :days días',
        'silent_help' => 'Esta conexión puede cobrar, pero Stripe no le ha enviado ningún evento. Revise el destino de webhooks de Connect de la plataforma para este modo o, con llaves de API, el endpoint en la cuenta de Stripe del comercio. Una cuenta con poca actividad también puede mostrar este aviso.',
    ],

];
