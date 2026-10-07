<?php

declare(strict_types=1);

/*
 * Conversión de moneda (plan 13, ADR-0063): alertas para los superadmins, la
 * página "Ajustes de pago" del tenant y el bloque de conversión de la página
 * de pago tienen sus propias secciones.
 */
return [

    'mail' => [
        'requires_review' => [
            'subject' => 'FIX de Banxico retenido para revisión',
            'line' => 'El FIX del :date (:rate MXN por USD) difiere demasiado del anterior, por lo que se guardó pero no se usará para cobros.',
            'action' => 'Revisa la cifra en Banxico. Mientras no se guarde un FIX normal, las conversiones usan el anterior mientras no esté obsoleto.',
        ],
        'stale' => [
            'subject' => 'El FIX de Banxico está obsoleto',
            'line' => 'El FIX utilizable más reciente es del :date (:rate MXN por USD). Las conversiones con el FIX de Banxico están bloqueadas hasta que se guarde uno vigente.',
            'action' => 'Revisa la variable BANXICO_SIE_TOKEN y la consulta programada. Los links con tipo de cambio fijo siguen funcionando.',
        ],
    ],

    /*
     * Página "Ajustes de pago" del tenant (ADR-0063, ADR-0048).
     */
    'settings' => [
        'navigation_group' => 'Configuración',
        'title' => 'Ajustes de pago',
        'subheading' => 'La conversión de moneda para tarjetas mexicanas y la vigencia de sus links de pago.',
        'save' => 'Guardar ajustes',
        'saved' => 'Se guardaron los ajustes de pago.',

        'return_secret' => [
            'action' => 'Clave de retorno',
            'heading' => 'Rotar la clave de retorno',
            'help' => 'Los pagadores que pagan un link con URL de retorno vuelven a tu sitio con una prueba firmada del pago. Esta clave la firma: tú la verificas de tu lado. Se genera una clave nueva y se muestra una sola vez; la actual sigue firmando durante 24 horas para que puedas desplegar la nueva sin interrupciones.',
            'submit' => 'Rotar y mostrar la clave',
            'rotated' => 'Clave de retorno rotada.',
        ],

        'fx' => [
            'heading' => 'Conversión de moneda',
            'description' => 'Una tarjeta emitida en México solo puede cobrarse en pesos mexicanos. Cuando un link en USD se paga con una, el pagador ve el monto en MXN y lo confirma antes de que se le cobre.',
            'enabled' => 'Convertir los links en USD pagados con tarjetas mexicanas',
            'enabled_help' => 'Si está apagada, una tarjeta mexicana no puede pagar un link en USD: se le informa al pagador que usted no puede cobrar ese monto en USD y no se cobra nada.',
            'mode' => 'Cómo se define el tipo de cambio',
            'mode_fixed' => 'Tipo de cambio fijo',
            'mode_fixed_help' => 'Usted define el tipo de cambio. Se cobra el monto en USD x el tipo de cambio, redondeado al centavo. No se aplica ajuste.',
            'mode_banxico_fix' => 'FIX de Banxico',
            'mode_banxico_fix_help' => 'El FIX que publica Banxico, más su ajuste. Si el último FIX tiene más de 4 días, las conversiones se bloquean hasta que llegue uno nuevo.',
            'fixed_rate' => 'Tipo de cambio fijo (MXN por USD)',
            'fixed_rate_help' => 'Hasta 6 decimales, por ejemplo 20.000000. Un link puede traer su propio tipo de cambio, que tiene prioridad.',
            'markup' => 'Ajuste sobre el FIX (puntos base)',
            'markup_help' => '100 puntos base = 1.00 %. De 0 a :max. Siempre se informa al pagador que existe el ajuste.',
            'quote_validity' => 'Vigencia de la cotización (minutos)',
            'quote_validity_help' => 'De :min a :max minutos. Después, si el monto cambió, se le puede pedir al pagador que confirme de nuevo.',
        ],

        'links' => [
            'heading' => 'Vigencia de los links',
            'description' => 'Aplica a los links que se crean sin vigencia. Ningún link puede durar más de :days días.',
            'default' => 'Vigencia por defecto',
            'default_help' => 'Se usa cuando un link se crea sin vigencia. No puede ser mayor que el máximo de abajo.',
            'max' => 'Vigencia máxima',
            'max_help' => 'Lo máximo que puede durar un link suyo: hasta :hours horas (:days días). Los links que ya existen conservan su vigencia.',
            'hours' => 'horas',
        ],

        'errors' => [
            'mode' => 'Elija cómo se define el tipo de cambio.',
            'fixed_rate_required' => 'Escriba el tipo de cambio fijo, o elija el FIX de Banxico.',
            'fixed_rate_format' => 'Escriba un número mayor que cero con máximo 6 decimales, por ejemplo 20.000000.',
            'markup' => 'El ajuste debe estar entre 0 y :max puntos base.',
            'quote_validity' => 'La cotización debe durar entre :min y :max minutos.',
            'max_expiration' => 'El máximo debe estar entre :min y :max horas (:days días).',
            'default_expiration' => 'La vigencia por defecto debe ser de al menos :min hora y no mayor que el máximo.',
        ],
    ],

];
