<?php

declare(strict_types=1);

/*
 * Payment vocabulary. Keys mirror lang/en/payments.php.
 */
return [

    'status' => [
        'authorized' => 'Autorizado',
        'captured' => 'Capturado',
        'pending' => 'Pendiente',
        'refunded' => 'Reembolsado',
        'partially_refunded' => 'Reembolsado parcialmente',
        'disputed' => 'En disputa',
        'failed' => 'Fallido',
        'canceled' => 'Cancelado',
        'expired' => 'Vencido',
        'unknown' => 'Estado desconocido',
    ],

    // Intentos de pago (plan 9.2, ADR-0050, ADR-0051), panel del tenant.
    'attempt_status' => [
        'requires_payment_method' => 'Esperando tarjeta',
        'requires_confirmation' => 'Esperando confirmación',
        'requires_action' => 'Verificación del banco',
        'requires_capture' => 'Autorizado, sin capturar',
        'processing' => 'Procesando',
        'succeeded' => 'Exitoso',
        'failed' => 'Fallido',
        'canceled' => 'Cancelado',
    ],

    'attempts' => [
        'section' => 'Intentos de pago',
        'empty' => 'Nadie ha intentado pagar este link todavía.',
        'id' => 'Pago',
        'status' => 'Estado',
        'amount' => 'Monto',
        'card' => 'Tarjeta',
        'card_value' => ':brand •••• :last4',
        'card_country' => 'País de la tarjeta',
        'failures' => 'Rechazos',
        'last_decline' => 'Último rechazo',
        'late_payment' => 'Pagado después del cierre del link',
        'needs_review' => 'Requiere revisión',
        'late_payment_yes' => 'Sí',
        'provider_payment_id' => 'Pago en Stripe',
        'created_at' => 'Iniciado',
        'succeeded_at' => 'Pagado',
    ],

    'review_reason' => [
        'closed_without_gateway' => 'Cerrado sin Stripe (la conexión perdió sus llaves): verifique en Stripe que no quede una retención en la tarjeta.',
        'succeeded_after_close' => 'Stripe indica que este pago se completó después de cerrar el intento: revíselo en Stripe y reembólselo si no debió cobrarse.',
    ],

    'decline_codes' => [
        'generic_decline' => 'Rechazada por el banco',
        'card_declined' => 'Tarjeta rechazada',
        'insufficient_funds' => 'Fondos insuficientes',
        'lost_card' => 'Tarjeta reportada como extraviada',
        'stolen_card' => 'Tarjeta reportada como robada',
        'expired_card' => 'Tarjeta vencida',
        'incorrect_cvc' => 'Código de seguridad incorrecto',
        'invalid_cvc' => 'Código de seguridad no válido',
        'incorrect_number' => 'Número de tarjeta incorrecto',
        'invalid_number' => 'Número de tarjeta no válido',
        'invalid_expiry_month' => 'Mes de vencimiento no válido',
        'invalid_expiry_year' => 'Año de vencimiento no válido',
        'incorrect_zip' => 'Código postal incorrecto',
        'processing_error' => 'Error de procesamiento',
        'do_not_honor' => 'Rechazada por el banco (no autorizada)',
        'fraudulent' => 'Rechazada como posible fraude',
        'card_not_supported' => 'Tarjeta no admitida',
        'currency_not_supported' => 'La tarjeta no admite la moneda',
        'card_velocity_exceeded' => 'Límite de la tarjeta excedido',
        'authentication_required' => 'Se requiere verificación del banco',
        'payment_intent_authentication_failure' => 'Falló la verificación del banco',
        'pickup_card' => 'Tarjeta retenida por el banco',
        'restricted_card' => 'Tarjeta restringida',
        'try_again_later' => 'Rechazo temporal: intente más tarde',
        'transaction_not_allowed' => 'Operación no permitida',
        'withdrawal_count_limit_exceeded' => 'Límite de uso de la tarjeta excedido',
        'invalid_account' => 'Cuenta de la tarjeta no válida',
    ],

    'checkout_block' => [
        'reauthentication_required' => 'Confirme su contraseña para continuar.',
        'callout' => 'Pagos pausados por posible prueba de tarjetas hasta el :date.',
        'callout_help' => 'El link recibió muchas tarjetas rechazadas en poco tiempo. Desbloquéelo solo si reconoce los intentos.',
        'unblock' => 'Desbloquear pagos',
        'unblock_heading' => '¿Desbloquear este link?',
        'unblock_help' => 'Los pagadores podrán intentar de nuevo de inmediato. La verificación de seguridad sigue activa tras el siguiente rechazo.',
        'unblocked' => 'Pagos desbloqueados.',
    ],

    'privacy_notice_missing' => [
        'heading' => 'La página de pago no pedirá los datos del pagador',
        'help' => 'Su cuenta no tiene aviso de privacidad, así que la página de pago no recaba datos del pagador para este link. Agregue su aviso de privacidad en la página Legal (Configuración) para recabarlos.',
        'action' => 'Ir a la página Legal',
    ],

    // Historial de pagos (ADR-0059), panel del comercio. Una fila por intento de pago.
    'resource' => [
        'singular' => 'pago',
        'plural' => 'pagos',
        'page' => [
            'subheading' => [
                'test' => 'Pagos del modo de prueba: no se mueve dinero real. También se listan los pagos rechazados y liberados.',
                'live' => 'Pagos del modo real. También se listan los pagos rechazados y liberados.',
            ],
            'view_title' => 'Pago',
        ],
        'fields' => [
            'date' => 'Fecha',
            'link' => 'Link',
            'amount' => 'Monto',
            'currency' => 'Moneda',
            'status' => 'Estado',
            'card' => 'Tarjeta',
            'validation' => 'Validación previa',
        ],
        'filters' => [
            'from' => 'Desde',
            'until' => 'Hasta',
            'from_indicator' => 'Desde el :date',
            'until_indicator' => 'Hasta el :date',
        ],
        'fail_open_help' => 'Su servidor no dio una respuesta válida y su política cobró el pago de todos modos: revíselo.',
        'empty' => [
            'heading' => 'Aún no hay pagos',
            'description' => 'Los pagos aparecen aquí en cuanto un pagador ingresa una tarjeta en uno de sus links.',
        ],
    ],

    'validation_outcome' => [
        'not_configured' => 'No se usó',
        'approved' => 'Aprobado',
        'rejected' => 'Rechazado por usted',
        'failed_open' => 'Falló, cobrado',
        'failed_closed' => 'Falló, no cobrado',
    ],

    'timeline' => [
        'title' => 'Línea de tiempo',
        'started' => 'Pago iniciado',
        'declined' => 'Tarjeta rechazada',
        'authorized' => 'Tarjeta autorizada',
        'capture_before' => 'Debe capturarse antes del :date',
        'validation' => 'Validación previa: :outcome',
        'captured' => 'Cobrado',
        'captured_fail_open' => 'Cobrado con su política "cobrar de todos modos" tras una validación fallida.',
        'released' => 'Autorización liberada, sin cobro',
        'released_rejected' => 'Su servidor rechazó el pago.',
        'released_fail_closed' => 'La validación falló y se aplicó su política "no cobrar".',
        'failed' => 'Pago fallido',
        'event' => 'Evento :type',
        'event_published' => 'Enviado a sus endpoints de webhooks como:',
        'event_pending' => 'Aún no se envía a sus endpoints.',
    ],

];
