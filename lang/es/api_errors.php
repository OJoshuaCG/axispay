<?php

declare(strict_types=1);

/*
 * Panel wording of public API error codes (plan 10.4), keyed by code. The
 * API itself answers in English; the panels show these instead.
 */
return [

    'tenant_suspended' => 'Su cuenta está en solo lectura en su estado actual: aquí no se pueden crear ni cancelar links de pago. Los links existentes siguen funcionando.',
    'gateway_not_ready' => 'Conecte Stripe en este modo (y complete sus requisitos) antes de crear links.',
    'parameter_missing' => 'Este campo es obligatorio.',
    'parameter_invalid' => 'Revise este valor.',
    'amount_invalid' => 'Escriba dígitos con hasta dos decimales y punto, sin separadores de miles.',
    'amount_below_minimum' => 'El monto es menor al mínimo de esta moneda.',
    'amount_above_maximum' => 'El monto es mayor al máximo permitido para esta moneda.',
    'currency_not_supported' => 'Esta moneda no está soportada.',
    'expiration_out_of_range' => 'La expiración está fuera del rango permitido.',
    'link_not_cancelable' => 'Este link está pagado, vencido o cancelado y ya no se puede cancelar.',
    'link_payment_in_progress' => 'Hay un pago en curso para este link; no se puede cancelar ahora.',
    'insufficient_scope' => 'No tiene el permiso para esto.',
    'internal_error' => 'Algo salió mal. Intente de nuevo.',
    'metadata_invalid' => 'Los metadatos no son válidos.',
    'payer_field_invalid' => 'Los campos del pagador no son válidos.',
    'return_url_not_allowed' => 'El dominio de la URL de retorno no está permitido para su cuenta.',
    'fx_not_available' => 'La conversión de moneda no está disponible.',

];
