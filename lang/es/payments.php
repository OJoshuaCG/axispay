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
        'late_payment_yes' => 'Sí',
        'provider_payment_id' => 'Pago en Stripe',
        'created_at' => 'Iniciado',
        'succeeded_at' => 'Pagado',
    ],

    'checkout_block' => [
        'callout' => 'Pagos pausados por posible prueba de tarjetas hasta el :date.',
        'callout_help' => 'El link recibió muchas tarjetas rechazadas en poco tiempo. Desbloquéelo solo si reconoce los intentos.',
        'unblock' => 'Desbloquear pagos',
        'unblock_heading' => '¿Desbloquear este link?',
        'unblock_help' => 'Los pagadores podrán intentar de nuevo de inmediato. La verificación de seguridad sigue activa tras el siguiente rechazo.',
        'unblocked' => 'Pagos desbloqueados.',
    ],

    'privacy_notice_missing' => [
        'heading' => 'La página de pago no pedirá los datos del pagador',
        'help' => 'Su cuenta no tiene la URL de su aviso de privacidad, así que la página de pago no recaba datos del pagador para este link. Solicite al administrador de la plataforma que agregue la URL de su aviso de privacidad para recabarlos.',
    ],

];
