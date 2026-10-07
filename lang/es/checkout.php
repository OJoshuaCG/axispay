<?php

declare(strict_types=1);

/*
| Página de pago (plan 11, DESIGN.md, ADR-0051). El pagador se trata de "tú";
| los paneles conservan "usted" (docs/frontend/i18n.md).
*/

return [
    'title' => [
        'pay' => 'Pagar a :merchant',
        'pay_short' => 'Pagar',
        'too_many_requests' => 'Demasiadas solicitudes',
        'not_found' => 'Enlace no encontrado',
    ],

    'summary' => [
        'pay_to' => 'Pago a :merchant',
        'total' => 'Total a pagar',
        'breakdown' => 'Detalle del pago',
        'expires' => 'Vence el :date',
    ],

    'redirect' => [
        'countdown' => 'Te llevaremos de vuelta a :merchant en :seconds s.',
        'stop' => 'Quedarme aquí',
    ],

    'payer' => [
        'heading' => 'Tus datos',
        'optional_label' => ':label (opcional)',
        'email' => 'Correo electrónico',
        'full_name' => 'Nombre completo',
        'phone' => 'Teléfono',
        'phone_country' => 'Código de país',
        'company_name' => 'Empresa',
        'tax_id' => 'RFC o identificación fiscal',
        'notes' => 'Notas',
        'address' => [
            'legend' => 'Dirección de facturación',
            'country' => 'País',
            'line1' => 'Calle y número',
            'line2' => 'Interior, departamento, etc.',
            'city' => 'Ciudad',
            'state' => 'Estado',
            'postal_code' => 'Código postal',
        ],
        'privacy' => ':merchant recibirá estos datos. Consulta su :link.',
        'privacy_link' => 'aviso de privacidad',
        'privacy_no_link' => ':merchant recibirá estos datos.',
    ],

    'card' => [
        'heading' => 'Tarjeta',
        'loading' => 'Cargando el formulario seguro de tarjeta…',
        'sandbox' => 'Sandbox: no se cobra ninguna tarjeta real.',
    ],

    'pay_amount' => 'Pagar :amount',
    'processing_payment' => 'Procesando pago…',
    'trust' => 'Pago seguro: los datos de tu tarjeta van cifrados directamente a Stripe.',

    'phase' => [
        'three_ds' => 'Esperando la confirmación de tu banco…',
        'validating' => 'Verificando tu pedido…',
    ],

    'messages' => [
        'declined' => 'La tarjeta fue rechazada. Intenta con otra o contacta a tu banco.',
        'authentication_failed' => 'No pudimos completar la verificación de tu banco. Intenta de nuevo o usa otra tarjeta.',
        'turnstile' => 'Por seguridad, confirma que eres una persona antes de volver a intentar.',
        'rate_limited' => '{1} Por seguridad, pausamos los pagos por un momento. Intenta de nuevo en :minutes minuto.|[2,*] Por seguridad, pausamos los pagos por un momento. Intenta de nuevo en :minutes minutos.',
        'error' => 'No pudimos procesar el pago. No se hizo ningún cargo. Intenta de nuevo.',
        'unavailable' => 'Este enlace no acepta pagos por ahora. Contacta a :merchant.',
        'too_many_requests' => '{1} Demasiadas solicitudes. Vuelve a intentarlo en :seconds segundo.|[2,*] Demasiadas solicitudes. Vuelve a intentarlo en :seconds segundos.',
        'session_expired' => 'Recarga la página para continuar.',
        'security_unavailable' => 'Los pagos en esta página no están disponibles en este momento. Vuelve a intentarlo más tarde.',
        'fix_fields' => 'Revisa los campos marcados.',
        'conversion_unavailable' => ':merchant no puede cobrar este monto en USD a tarjetas emitidas en México. No se hizo ningún cargo. Contacta a :merchant.',
    ],

    /*
    | Conversión de USD a pesos mexicanos (plan 13, ADR-0063): la leyenda de la
    | página y la pantalla de confirmación antes del cobro.
    */
    'fx' => [
        'date_format' => 'd/m/Y',
        'legend' => 'Si pagas con una tarjeta emitida en México, se cobrarán :amount. Tipo de cambio: :rate. :markup',
        'source' => [
            'banxico_fix' => 'Banxico FIX del :date',
            'merchant' => 'tipo de cambio definido por el comercio',
        ],
        'markup' => 'Incluye un ajuste del comercio de :percent %.',
        'confirm' => [
            'title' => 'Confirma el cobro en pesos mexicanos',
            'intro' => 'Tu tarjeta fue emitida en México. Este cobro se realizará en pesos mexicanos.',
            'original' => 'Monto original',
            'amount' => 'Monto a cobrar',
            'rate' => 'Tipo de cambio aplicado',
            'pay' => 'Pagar :amount',
            'cancel' => 'Cancelar',
        ],
    ],

    'turnstile' => [
        'label' => 'Verificación de seguridad',
    ],

    'states' => [
        'processing' => [
            'heading' => 'Tu pago se está procesando',
            'body' => 'No cierres esta página.',
        ],
        'timeout' => [
            'heading' => 'Aún no tenemos la confirmación final',
            'body' => 'No vuelvas a pagar: revisa este enlace más tarde.',
            'action' => 'Revisar de nuevo',
        ],
        'paid' => [
            'heading' => 'Pago realizado',
            'paid_on' => 'Pagado el :date',
            'return' => 'Volver a :merchant',
        ],
        'already_paid' => [
            'heading' => 'Este cobro ya fue pagado',
        ],
        'expired' => [
            'heading' => 'Este enlace de pago expiró',
            'body' => 'Contacta a :merchant.',
        ],
        'canceled' => [
            'heading' => 'Este enlace de pago ya no está disponible',
        ],
        'blocked' => [
            'heading' => 'Este enlace no acepta pagos',
            'body' => 'Este enlace no acepta pagos por ahora. Contacta a :merchant.',
        ],
        'rejected' => [
            'heading' => ':merchant no pudo aceptar este pago',
            'message_from' => 'Mensaje de :merchant:',
            'fallback' => 'Contacta a :merchant para más información.',
        ],
        'voided' => 'No se hizo ningún cargo. Tu banco puede mostrar un cargo pendiente por unos días; se liberará sin que hagas nada.',
        'too_many_requests' => [
            'heading' => 'Demasiadas solicitudes',
            'body' => 'Espera un momento y vuelve a cargar esta página.',
        ],
        'not_found' => [
            'heading' => 'No encontramos este enlace',
            'body' => 'Revisa que la dirección esté completa.',
        ],
    ],

    'error_pages' => [
        404 => [
            'heading' => 'No encontramos esta página',
            'body' => 'Revisa que la dirección esté completa.',
        ],
        419 => [
            'heading' => 'Tu sesión expiró',
            'body' => 'Recarga la página para continuar.',
        ],
        429 => [
            'heading' => 'Demasiadas solicitudes',
            'body' => 'Espera un momento y vuelve a cargar esta página.',
        ],
        500 => [
            'heading' => 'Algo salió mal',
            'body' => 'No se hizo ningún cargo. Vuelve a intentarlo en unos minutos.',
        ],
        503 => [
            'heading' => 'Volvemos en un momento',
            'body' => 'Estamos haciendo mantenimiento. Vuelve a intentarlo en unos minutos.',
        ],
    ],

    'contact' => 'Escribe a :email',

    'footer' => [
        'powered_by' => 'Con la tecnología de :platform',
        'processed_by' => 'Procesado por Stripe',
        'help' => '¿Dudas sobre tu pago? Escribe a :email',
    ],

    'legal' => [
        'nav' => 'Documentos legales',
        'link' => [
            'privacy' => 'Aviso de privacidad',
            'terms' => 'Términos',
        ],
        'title' => [
            'privacy' => 'Aviso de privacidad',
            'terms' => 'Términos y condiciones',
        ],
        'close' => 'Cerrar',
        'new_tab' => '(se abre en una pestaña nueva)',
        'back' => 'Volver al pago',
        'external' => ':merchant publica este documento en su sitio web.',
        'open_external' => 'Abrir el documento',
        'platform_title' => 'Información legal',
        'platform_intro' => 'El aviso de privacidad y los términos y condiciones de :platform, la plataforma detrás de esta página de pago.',
        'platform_external' => 'Publicado en otro sitio web.',
    ],

    'errors' => [
        'required' => 'Este campo es obligatorio.',
        'email' => 'Escribe un correo electrónico válido.',
        'phone' => 'Escribe un número de teléfono válido.',
        'length' => 'Usa entre :min y :max caracteres.',
        'too_long' => 'Usa como máximo :max caracteres.',
        'tax_id' => 'Usa hasta :max letras y números.',
        'country' => 'Elige un país de la lista.',
        'postal_code_mx' => 'Escribe el código postal de 5 dígitos.',
    ],

    'sandbox' => [
        'label' => 'Tarjeta de prueba (sandbox)',
        'scenario' => [
            'success' => 'Aprobada',
            'decline' => 'Rechazada',
            'funds' => 'Fondos insuficientes',
            'threeds' => 'Verificación del banco (3D Secure)',
            'processing' => 'Procesamiento lento',
            'foreign' => 'Tarjeta extranjera aprobada (EE. UU.)',
        ],
        'bank' => [
            'title' => 'Banco de sandbox',
            'body' => '¿Aprobar este pago de prueba?',
            'approve' => 'Aprobar',
            'fail' => 'Fallar la verificación',
        ],
    ],

    'mail' => [
        'blocked' => [
            'subject' => 'Se bloqueó un link de pago (:mode)',
            'line' => '{1} El link de pago :link recibió muchas tarjetas rechazadas en poco tiempo y dejó de aceptar pagos durante :hours hora, como protección contra pruebas de tarjetas.|[2,*] El link de pago :link recibió muchas tarjetas rechazadas en poco tiempo y dejó de aceptar pagos durante :hours horas, como protección contra pruebas de tarjetas.',
            'button' => 'Ver el link',
            'action' => 'Si los rechazos fueron legítimos, puede desbloquearlo desde el detalle del link en el panel.',
        ],
    ],
];
