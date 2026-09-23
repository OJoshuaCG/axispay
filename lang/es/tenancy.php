<?php

declare(strict_types=1);

/*
 * Keys mirror lang/en/tenancy.php.
 */
return [

    'status' => [
        'pending_onboarding' => 'Alta pendiente',
        'active' => 'Activo',
        'grace' => 'Periodo de gracia',
        'suspended' => 'Suspendido',
        'closed' => 'Cerrado',
    ],

    'mode' => [
        'test' => 'Modo de prueba',
        'live' => 'Modo real',
        'test_short' => 'Prueba',
        'live_short' => 'Real',
        'switch_to_live' => 'Cambiar a datos reales',
        'switch_to_test' => 'Cambiar a datos de prueba',
    ],

    'banner' => [
        'pending_onboarding' => 'Su cuenta se está configurando. Algunas funciones aún no están disponibles.',
        'grace' => 'Su cuenta está en periodo de gracia. Póngase en contacto con nosotros para mantenerla activa.',
        'suspended' => 'Su cuenta está suspendida. Puede consultar sus datos, pero no puede hacer cambios ni crear links nuevos.',
    ],

    'mail' => [
        'status_changed' => [
            'subject' => 'El estado de su cuenta ha cambiado',
            'line' => 'El estado de su cuenta ahora es: :status.',
            'contact' => 'Si tiene preguntas, comuníquese con soporte.',
        ],
    ],

];
