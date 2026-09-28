<?php

declare(strict_types=1);

/*
| Página de pago (plan 11, DESIGN.md, ADR-0051). El pagador se trata de "tú";
| los paneles conservan "usted" (docs/frontend/i18n.md).
*/

return [
    'title' => [
        'pay' => 'Pagar a :merchant',
        'too_many_requests' => 'Demasiadas solicitudes',
        'not_found' => 'Enlace no encontrado',
    ],

    'summary' => [
        'pay_to' => 'Pago a :merchant',
        'total' => 'Total a pagar',
        'description' => 'Descripción',
        'expires' => 'Vence el :date',
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
        'rate_limited' => 'Por seguridad, pausamos los pagos por un momento. Intenta de nuevo en :minutes minutos.',
        'error' => 'No pudimos procesar el pago. No se hizo ningún cargo. Intenta de nuevo.',
        'unavailable' => 'Este enlace no acepta pagos por ahora. Contacta a :merchant.',
        'too_many_requests' => 'Demasiadas solicitudes. Vuelve a intentarlo en :seconds segundos.',
        'security_unavailable' => 'Los pagos en esta página no están disponibles en este momento. Vuelve a intentarlo más tarde.',
        'fix_fields' => 'Revisa los campos marcados.',
    ],

    'turnstile' => [
        'label' => 'Verificación de seguridad',
    ],

    'states' => [
        'processing' => [
            'heading' => 'Tu pago se está procesando',
            'body' => 'Tu pago se está procesando. No cierres esta página.',
        ],
        'timeout' => [
            'heading' => 'Aún no tenemos la confirmación final',
            'body' => 'Aún no tenemos la confirmación final. No vuelvas a pagar: revisa este enlace más tarde.',
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
            'body' => 'Este enlace de pago expiró. Contacta a :merchant.',
        ],
        'canceled' => [
            'heading' => 'Este enlace de pago ya no está disponible',
            'body' => 'Este enlace de pago ya no está disponible.',
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
            'body' => 'No encontramos este enlace. Revisa que la dirección esté completa.',
        ],
    ],

    'contact' => 'Escribe a :email',

    'footer' => [
        'powered_by' => 'Con la tecnología de :platform',
        'processed_by' => 'Procesado por Stripe',
        'privacy' => 'Aviso de privacidad',
        'support' => 'Soporte: :email',
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
            'subject' => 'Se bloqueó un link de pago (modo :mode)',
            'line' => 'El link de pago :link recibió muchas tarjetas rechazadas en poco tiempo y dejó de aceptar pagos durante :hours horas, como protección contra pruebas de tarjetas.',
            'action' => 'Si los rechazos fueron legítimos, puede desbloquearlo desde el detalle del link en el panel.',
        ],
    ],
];
