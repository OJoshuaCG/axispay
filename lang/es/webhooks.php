<?php

declare(strict_types=1);

/*
 * Webhooks salientes (plan 15.1-15.7) y validación previa al cobro (15.8).
 * Panel y correos al comercio: registro formal (usted), docs/frontend/i18n.md.
 */
return [

    /*
     * Panel: endpoints de webhooks (plan 15.1). Configuración → Webhooks.
     */
    'singular' => 'endpoint de webhook',
    'plural' => 'endpoints de webhooks',

    'navigation' => [
        'group' => 'Configuración',
    ],

    'page' => [
        'subheading' => [
            'test' => 'Endpoints del modo de prueba: solo reciben los eventos de pagos de prueba. Hasta :max por modo.',
            'live' => 'Endpoints del modo real: reciben los eventos de pagos reales. Hasta :max por modo.',
        ],
        'view_title' => 'Endpoint de webhook',
        'view_subheading' => [
            'test' => 'Endpoint del modo de prueba.',
            'live' => 'Endpoint del modo real.',
        ],
    ],

    'fields' => [
        'url' => 'URL',
        'url_help' => [
            'test' => 'Donde su servidor recibe los eventos. Debe empezar con https:// y usar el puerto 443 u 8443.',
            'live' => 'Donde su servidor recibe los eventos de pagos reales. Debe empezar con https:// y usar el puerto 443 u 8443.',
        ],
        'description' => 'Descripción',
        'description_help' => 'Opcional. Por ejemplo, "Sistema de pedidos".',
        'all_events' => 'Enviar todos los eventos',
        'all_events_help' => 'Incluye los eventos que se agreguen al catálogo más adelante.',
        'events' => 'Eventos',
        'events_help' => 'Elija solo los eventos que usa su servidor.',
        'status' => 'Estado',
        'health' => 'Entregas',
        'healthy' => 'Sin fallos continuos',
        'no_deliveries' => 'Aún sin entregas',
        'previous_secret' => 'Clave secreta anterior',
        'created_at' => 'Creado',
    ],

    'all_events' => 'Todos los eventos',
    'copied' => 'Copiado.',
    'failing_since' => 'Fallando desde el :date',
    'failing_help' => 'Todas las entregas a este endpoint han fallado desde entonces. Tras 5 días de fallos continuos se deshabilita y se le avisa por correo.',
    'previous_secret_until' => 'También firma hasta el :date (se envían ambas firmas).',

    'status' => [
        'enabled' => 'Habilitado',
        'disabled_by_user' => 'Deshabilitado',
        'disabled_by_failures' => 'Deshabilitado por fallos',
    ],

    'status_help' => [
        'disabled_by_user' => 'No se envían eventos. Habilítelo para recibir los eventos nuevos; los perdidos se pueden reenviar desde el registro de entregas.',
        'disabled_by_failures' => 'Falló de forma continua durante 5 días. Corrija su servidor, envíe un evento de prueba y habilítelo de nuevo.',
    ],

    'event_types' => [
        'payment_link.created' => 'Se creó un link (API o panel).',
        'payment_link.opened' => 'Se abrió un link (la primera vez y luego como máximo cada 30 minutos).',
        'payment_link.paid' => 'Se pagó un link.',
        'payment_link.expired' => 'Expiró un link.',
        'payment_link.canceled' => 'Se canceló un link.',
        'payment.processing' => 'Un pago está en procesamiento.',
        'payment.succeeded' => 'Un pago fue exitoso.',
        'payment.failed' => 'Se rechazó una tarjeta.',
        'refund.created' => 'Se solicitó un reembolso.',
        'refund.succeeded' => 'Se completó un reembolso.',
        'refund.failed' => 'Falló un reembolso.',
        'dispute.created' => 'Se abrió una disputa.',
        'dispute.closed' => 'Se cerró una disputa.',
        'ping' => 'Evento de prueba enviado desde el panel.',
    ],

    'form' => [
        'url_required' => 'Escriba la URL.',
        'events_required' => 'Elija al menos un evento, o envíe todos los eventos.',
    ],

    'actions' => [
        'create' => 'Agregar endpoint',
        'create_help' => [
            'test' => 'El endpoint recibe los eventos del modo de prueba. Se genera una clave secreta de firma que se muestra una sola vez.',
            'live' => 'El endpoint recibe los eventos de pagos reales. Se genera una clave secreta de firma que se muestra una sola vez. Se avisa por correo a los propietarios de la cuenta.',
        ],
        'create_submit' => 'Agregar endpoint',
        'edit' => 'Editar',
        'edit_heading' => 'Editar el endpoint',
        'save' => 'Guardar',
        'more' => 'Más',
        'send_test' => 'Enviar evento de prueba',
        'send_test_heading' => '¿Enviar un evento de prueba?',
        'send_test_help' => 'Se envía ahora un evento "ping" firmado a :host y se muestra la respuesta. No se reintenta y no cambia el estado del endpoint.',
        'send_test_submit' => 'Enviar',
        'rotate' => 'Rotar clave secreta',
        'rotate_heading' => '¿Rotar la clave secreta de firma?',
        'rotate_help' => 'Se genera una clave secreta nueva que se muestra una sola vez. La clave actual sigue firmando durante 24 horas (se envían ambas firmas), para que pueda actualizar su servidor sin perder eventos.',
        'rotate_submit' => 'Rotar',
        'reveal' => 'Revelar clave secreta',
        'reveal_heading' => '¿Revelar la clave secreta de firma?',
        'reveal_help' => 'La clave secreta actual se muestra una vez más. Queda registrado en la auditoría.',
        'reveal_submit' => 'Revelar',
        'disable' => 'Deshabilitar',
        'disable_heading' => '¿Deshabilitar el endpoint?',
        'disable_help' => 'Ya no se le envían eventos y se descartan los reintentos pendientes. Puede habilitarlo de nuevo más adelante.',
        'disable_submit' => 'Deshabilitar',
        'enable' => 'Habilitar',
        'enable_heading' => '¿Habilitar el endpoint?',
        'enable_help' => 'Recibe los eventos creados a partir de ahora. Los eventos perdidos se pueden reenviar desde el registro de entregas.',
        'enable_submit' => 'Habilitar',
        'delete' => 'Eliminar',
        'delete_heading' => '¿Eliminar el endpoint?',
        'delete_help' => 'Se eliminan el endpoint de :host y su registro de entregas. Se dejan de enviar eventos a esa URL. No se puede deshacer.',
        'delete_submit' => 'Eliminar',
    ],

    'secret' => [
        'label' => 'Clave secreta de firma',
        'heading' => [
            'created' => 'Copie la clave secreta de firma',
            'rotated' => 'Copie la nueva clave secreta de firma',
            'revealed' => 'Clave secreta de firma',
        ],
        'description' => 'Su servidor la usa para comprobar que cada solicitud viene de nosotros.',
        'warning_heading' => 'Solo se muestra ahora',
        'warning' => 'Guárdela solo en su servidor, nunca en un navegador ni en una app. Si se filtra, rótela.',
        'copy' => 'Copiar',
        'copied' => 'Clave copiada.',
        'copy_failed' => 'No se pudo copiar. Selecciónela y cópiela a mano.',
        'done' => 'Ya copié la clave',
    ],

    'test_result' => [
        'heading' => 'Resultado de la prueba',
        'webhook_heading' => 'Evento de prueba a :host',
        'validation_heading' => 'Prueba de validación a :host',
        'delivered' => 'Entregado',
        'not_delivered' => 'No entregado',
        'validation_approved' => 'Su servidor aprobó el pago de ejemplo',
        'validation_rejected' => 'Su servidor rechazó el pago de ejemplo',
        'validation_invalid' => 'La respuesta de su servidor no tiene un formato válido',
        'http_status' => 'Estado HTTP',
        'no_answer' => 'Sin respuesta',
        'latency' => 'Tiempo',
        'latency_value' => ':ms ms',
        'error' => 'Problema',
        'decision' => 'Decisión',
        'decision_approve' => 'Aprobar',
        'decision_reject' => 'Rechazar',
        'decision_none' => 'Ninguna',
        'errors' => 'Errores',
        'warnings' => 'Advertencias',
        'excerpt' => 'Respuesta recibida',
        'no_excerpt' => 'La respuesta no tiene cuerpo.',
        'close' => 'Cerrar',
        'next_step' => [
            'http_status' => 'Revise que su servidor responda con un código de éxito (200–299) sin redirigir, y envíe el evento de prueba de nuevo.',
            'timeout' => 'Su servidor debe responder en menos de 10 segundos: responda primero y procese el evento después.',
            'dns_error' => 'Revise que el dominio de la URL esté bien escrito y tenga registros DNS públicos.',
            'tls_error' => 'Revise el certificado de su servidor: debe ser válido, vigente y emitido para ese dominio.',
            'connection_error' => 'Revise que su servidor esté en línea y acepte conexiones en ese puerto desde internet.',
            'response_too_large' => 'Responda con un cuerpo corto (por ejemplo, vacío o "ok"); el contenido de la respuesta no se usa.',
            'blocked_destination' => 'Use una URL pública: no se permiten direcciones privadas, locales ni reservadas.',
            'endpoint_disabled' => 'Habilite el endpoint y envíe el evento de prueba de nuevo.',
            'internal_error' => 'Fue un error de nuestra parte. Inténtelo de nuevo en unos minutos.',
        ],
    ],

    'delivery_status' => [
        'pending' => 'Pendiente',
        'succeeded' => 'Entregado',
        'failed' => 'Fallido',
        'abandoned' => 'Abandonado',
    ],

    'delivery_error' => [
        'http_status' => 'Su servidor no respondió con un código de éxito (200–299)',
        'timeout' => 'Sin respuesta en 10 segundos',
        'dns_error' => 'No se pudo resolver el dominio',
        'tls_error' => 'Falló la conexión segura (TLS)',
        'connection_error' => 'Falló la conexión',
        'response_too_large' => 'La respuesta era demasiado grande',
        'blocked_destination' => 'Destino bloqueado por las reglas de seguridad',
        'endpoint_disabled' => 'El endpoint estaba deshabilitado',
        'internal_error' => 'Error interno',
    ],

    'delivery_trigger' => [
        'automatic' => 'automático',
        'manual' => 'reenvío manual',
        'test' => 'prueba',
    ],

    'deliveries' => [
        'title' => 'Registro de entregas',
        'singular' => 'entrega',
        'plural' => 'entregas',
        'event' => 'Evento',
        'status' => 'Estado',
        'attempt' => 'Intento',
        'attempt_value' => 'N.º :number (:trigger)',
        'http_status' => 'HTTP',
        'latency' => 'Tiempo',
        'time' => 'Enviado',
        'next_retry' => 'Siguiente reintento :since',
        'details' => 'Detalles',
        'details_heading' => 'Entrega de :type',
        'resend' => 'Reenviar',
        'resend_heading' => '¿Reenviar este evento?',
        'resend_help' => 'El evento :type se envía una vez más a :host, con el mismo ID y el mismo cuerpo (el receptor deduplica por webhook-id).',
        'resend_submit' => 'Reenviar',
        'empty_heading' => 'Aún no hay entregas',
        'empty_description' => 'Las entregas aparecen aquí cuando se envía un evento a este endpoint. El registro se conserva 30 días.',
    ],

    'empty' => [
        'heading' => 'No hay endpoints de webhooks',
        'description' => 'Agregue un endpoint para que su servidor se entere de pagos, links y reembolsos en cuanto ocurren.',
    ],

    'notifications' => [
        'created' => 'Endpoint de :host agregado.',
        'updated' => 'Endpoint guardado.',
        'rotated' => 'Clave secreta rotada. La anterior sigue firmando durante 24 horas.',
        'disabled' => 'Endpoint deshabilitado.',
        'enabled' => 'Endpoint habilitado.',
        'deleted' => 'Endpoint eliminado.',
        'resent' => 'El evento quedó en cola para enviarse de nuevo.',
    ],

    'errors' => [
        'reauthentication_required' => 'Confirme su contraseña para continuar.',
        'tenant_read_only' => 'Su cuenta está en solo lectura en su estado actual: no se pueden crear ni modificar endpoints de webhooks. Aún puede deshabilitarlos o eliminarlos.',
        'too_many_endpoints' => 'Alcanzó el máximo de endpoints de webhooks para este modo.',
        'no_events' => 'Elija al menos un evento, o todos los eventos.',
        'unknown_event' => 'Uno de los eventos elegidos no existe.',
        'description_too_long' => 'La descripción puede tener hasta 255 caracteres.',
        'endpoint_disabled' => 'El endpoint está deshabilitado. Habilítelo antes de reenviar eventos.',
    ],

    'destination' => [
        'invalid_url' => 'Escriba una URL válida.',
        'too_long' => 'La URL puede tener hasta 2048 caracteres.',
        'scheme_not_allowed' => 'La URL debe empezar con https://.',
        'credentials_in_url' => 'La URL no puede incluir usuario ni contraseña.',
        'port_not_allowed' => 'La URL debe usar el puerto 443 u 8443.',
        'ip_literal_host' => 'Use un nombre de dominio, no una dirección IP.',
        'forbidden_host' => 'Este dominio no puede recibir webhooks.',
        'unresolvable_host' => 'El dominio de la URL no se puede resolver.',
        'forbidden_address' => 'El dominio apunta a una dirección privada o reservada.',
    ],

    'mail' => [
        'footer' => 'Si no esperaba este cambio, revise sus endpoints de webhooks y los accesos de su equipo.',
        'created' => [
            'subject' => 'Se agregó un endpoint de webhook (:mode)',
            'line' => 'Se agregó un endpoint de webhook para :host en :mode.',
        ],
        'updated' => [
            'subject' => 'Se modificó un endpoint de webhook (:mode)',
            'line' => 'Se modificó el endpoint de webhook para :host en :mode.',
        ],
        'secret_rotated' => [
            'subject' => 'Se rotó una clave secreta de webhook (:mode)',
            'line' => 'Se rotó la clave secreta de firma del endpoint de webhook para :host en :mode. La clave anterior sigue siendo válida 24 horas.',
        ],
        'disabled_by_failures' => [
            'subject' => 'Se deshabilitó un endpoint de webhook por fallos continuos (:mode)',
            'line' => 'El endpoint de webhook para :host falló de forma continua durante 5 días y se deshabilitó en :mode. Corríjalo y vuelva a habilitarlo desde el panel.',
        ],
        'deleted' => [
            'subject' => 'Se eliminó un endpoint de webhook (:mode)',
            'line' => 'Se eliminó el endpoint de webhook para :host en :mode.',
        ],
    ],

    /*
     * Validación previa al cobro (plan 15.8, ADR-0058).
     */
    'validation' => [
        'page' => [
            'title' => 'Validación previa al cobro',
            'subheading' => [
                'test' => 'Modo de prueba. Después de autorizar la tarjeta y antes de cobrarla, le preguntamos a su servidor si seguimos.',
                'live' => 'Modo real. Después de autorizar la tarjeta y antes de cobrarla, le preguntamos a su servidor si seguimos. Aplica a pagos reales.',
            ],
            'not_configured' => 'Sin configurar',
            'not_configured_help' => 'Los pagos de este modo se cobran sin preguntarle a su servidor.',
            'how_it_works' => 'Una vez configurada, cada pago espera hasta 5 segundos a que su servidor responda "approve" o "reject" (por ejemplo, para revisar el inventario o el pedido). Los links pueden usarla por defecto o link por link.',
            'settings' => 'Configuración',
            'default_on' => 'Sí: los links nuevos le preguntan a su servidor salvo que la API indique otra cosa',
            'default_off' => 'No: solo los links creados con validación le preguntan a su servidor',
            'never' => 'Nunca',
        ],
        'fields' => [
            'url' => 'URL de validación',
            'failure_policy' => 'Si su servidor falla',
            'failure_policy_help' => 'Es un fallo no responder en 5 segundos, responder con un error o enviar una respuesta que no es válida.',
            'enabled_by_default' => 'Usarla por defecto en los links nuevos',
            'enabled_by_default_help' => 'Aplica cuando la API no indica si un link usa la validación. Los links creados en el panel siguen esta opción.',
            'last_success_at' => 'Última respuesta válida',
        ],
        'actions' => [
            'configure' => 'Configurar',
            'configure_help' => [
                'test' => 'Validación del modo de prueba. Se genera una clave secreta de firma propia que se muestra una sola vez.',
                'live' => 'Validación de pagos reales. Se genera una clave secreta de firma propia que se muestra una sola vez. Se avisa por correo a los propietarios de la cuenta.',
            ],
            'edit' => 'Editar configuración',
            'save' => 'Guardar',
            'test' => 'Probar validación',
            'test_heading' => '¿Enviar una validación de ejemplo?',
            'test_help' => 'Se envían ahora a :host datos de ejemplo firmados y marcados con "test": true, y se muestran la respuesta, el tiempo y si el formato es válido. No cobra nada y no cuenta para la alerta de fallos.',
            'test_submit' => 'Enviar',
            'rotate_heading' => '¿Rotar la clave secreta de la validación?',
            'rotate_help' => 'Se genera una clave secreta nueva que se muestra una sola vez. La actual sigue firmando durante 24 horas (se envían ambas firmas).',
            'remove' => 'Eliminar',
            'remove_heading' => '¿Eliminar la validación previa?',
            'remove_help' => 'Los links nuevos ya no podrán usarla. Los links ya creados con validación NO se cobran hasta que se configure de nuevo una URL en este modo, sin importar la política de fallos. El registro de llamadas se conserva 30 días.',
            'remove_submit' => 'Eliminar',
        ],
        'alert' => [
            'heading' => 'Su validación previa está fallando',
            'description' => 'Las últimas :failures llamadas fallaron seguidas (la última: :date). La validación sigue activa y se está aplicando su política: ":policy". Revise su servidor y use "Probar validación".',
        ],
        'notifications' => [
            'configured' => 'Validación previa configurada.',
            'updated' => 'Configuración guardada.',
            'removed' => 'Validación previa eliminada.',
        ],
        'outcome' => [
            'approved' => 'Aprobada',
            'rejected' => 'Rechazada',
            'failed' => 'Fallida',
        ],
        'failure_kind' => [
            'timeout' => 'Sin respuesta en 5 segundos',
            'connection_error' => 'Falló la conexión',
            'tls_error' => 'Falló la conexión segura (TLS)',
            'http_error' => 'Estado HTTP distinto de 200',
            'invalid_response' => 'La respuesta no es válida',
            'blocked_destination' => 'Destino bloqueado por las reglas de seguridad',
            'endpoint_missing' => 'No hay URL de validación configurada',
        ],
        'final_decision' => [
            'charge' => 'Cobrado',
            'block' => 'No cobrado',
        ],
        'calls' => [
            'title' => 'Llamadas recientes',
            'description' => 'Todas las llamadas de validación de los últimos 30 días, de la más reciente a la más antigua.',
            'time' => 'Hora',
            'test' => 'Prueba',
            'link' => 'Link',
            'attempt_number' => 'Validación n.º :number del link',
            'attempt' => 'Número de validación',
            'outcome' => 'Resultado',
            'reason' => 'Motivo: :reason',
            'reason_code' => 'Motivo',
            'failure' => 'Fallo',
            'failure_with_policy' => ':kind; se aplicó: :policy',
            'policy_applied' => 'Política aplicada',
            'final_decision' => 'Cobro',
            'http_status' => 'HTTP',
            'latency' => 'Tiempo',
            'id' => 'Llamada',
            'empty_heading' => 'Aún no hay llamadas',
            'empty_description' => 'Las llamadas aparecen aquí cuando se valida un pago o cuando usa "Probar validación".',
        ],
        'link_calls' => [
            'section' => 'Validaciones previas al cobro',
            'description' => 'Cada vez que se le preguntó a su servidor por un pago de este link.',
            'empty' => 'Aún no se le ha preguntado a su servidor por este link.',
        ],
        'policy' => [
            'fail_closed' => [
                'label' => 'No cobrar (recomendado)',
                'explanation' => 'Si su servidor no responde en 5 segundos, responde con un error o envía una respuesta inválida, el pago no se cobra: se libera la autorización de la tarjeta y se le pide al pagador que lo contacte. Nunca se cobra sin su aprobación, pero una caída de su servidor detiene sus ventas.',
            ],
            'fail_open' => [
                'label' => 'Cobrar de todos modos',
                'explanation' => 'Si su servidor no responde en 5 segundos, responde con un error o envía una respuesta inválida, el pago se cobra de todos modos y queda marcado en el pago y en su webhook como "validación fallida, cobrado". Sus ventas siguen durante una caída, pero debe revisar esos pagos y reembolsarlos si hace falta.',
            ],
        ],
        'problems' => [
            'status_not_200' => 'La respuesta debe tener el estado HTTP 200 (no se siguen redirecciones).',
            'body_too_large' => 'La respuesta pesa más de 4 KB.',
            'invalid_json' => 'La respuesta no es un objeto JSON.',
            'decision_missing' => 'La respuesta no tiene el campo "decision".',
            'decision_invalid' => 'El campo "decision" debe ser "approve" o "reject".',
            'content_type_not_json' => 'La respuesta debería tener el encabezado Content-Type: application/json.',
            'reason_code_invalid' => 'El campo "reason_code" solo admite minúsculas, dígitos y guiones bajos, hasta 64 caracteres; se ignoró.',
            'payer_message_too_long' => 'El campo "payer_message" tiene más de 200 caracteres; se recortó.',
            'payer_message_invalid' => 'El campo "payer_message" debe ser texto; se ignoró.',
            'cancel_link_invalid' => 'El campo "cancel_link" debe ser true o false; se tomó como false.',
        ],
        'mail' => [
            'footer' => 'Si no esperaba esto, revise la configuración de la validación previa y los accesos de su equipo.',
            'configured' => [
                'subject' => 'Se configuró una URL de validación previa (:mode)',
                'line' => 'Los pagos en :mode ahora pueden ser validados por :host antes de cobrarse.',
            ],
            'updated' => [
                'subject' => 'Se modificó la validación previa (:mode)',
                'line' => 'Se modificó la configuración de la validación previa en :mode. La URL apunta a :host.',
            ],
            'secret_rotated' => [
                'subject' => 'Se rotó la clave secreta de la validación previa (:mode)',
                'line' => 'Se rotó la clave secreta de firma de la validación previa de :host en :mode. La clave anterior sigue siendo válida durante 24 horas.',
            ],
            'removed' => [
                'subject' => 'Se eliminó la URL de validación previa (:mode)',
                'line' => 'Se eliminó la URL de validación previa de :host en :mode. Los links creados con validación no se cobran hasta que se configure una URL de nuevo.',
            ],
            'failing' => [
                'subject' => 'Su validación previa está fallando (:mode)',
                'line' => 'Las últimas :failures llamadas de validación previa a :host en :mode fallaron seguidas. La validación sigue activa y se está aplicando su política de fallos. Revise su servidor y use "Probar validación" en el panel.',
            ],
        ],
    ],

];
