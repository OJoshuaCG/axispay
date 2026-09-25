<?php

declare(strict_types=1);

/*
 * Copy for resources/views/design-system.blade.php. Keys mirror
 * lang/en/design-system.php. Technical identifiers stay untranslated.
 */
return [

    'title' => 'Sistema de diseño',
    'lead' => 'Tokens y componentes en un solo lugar. Cambie el tema y el idioma en la parte superior para revisar todas las combinaciones.',

    'primitives' => [
        'title' => 'Colores primitivos',
        'lead' => 'Escalas en bruto. Use tokens semánticos en los componentes.',
        'status_group' => 'Estado (ámbar / rojo)',
    ],

    'semantic' => [
        'title' => 'Tokens semánticos',
        'lead' => 'Cambian automáticamente con el tema.',
    ],

    'typography' => [
        'title' => 'Tipografía',
        'sample' => 'Pagos seguros',
        'primary_text' => 'Texto principal (text-fg)',
        'secondary_text' => 'Texto secundario (text-fg-secondary)',
        'muted_text' => 'Texto atenuado (text-fg-muted): solo para contenido no esencial',
        'links' => 'Un :prose en línea y un :visited.',
        'prose_link' => 'enlace en el texto',
        'visited_link' => 'enlace visitado',
        'sans_heading' => 'Cobra en segundos',
        'sans_sample' => 'Cada página, etiqueta y mensaje usa Mukta, en inglés y en español.',
        'numeric_lead' => 'Todos los dígitos tienen el mismo ancho: los montos se alinean en columnas y no saltan al actualizarse.',
        'subtotal' => 'Subtotal',
        'fee' => 'Comisión de procesamiento',
        'total' => 'Total',
    ],

    'foundations' => [
        'label' => 'Espaciado, radios y sombras',
        'spacing' => 'Espaciado',
        'radius' => 'Radios',
        'shadows' => 'Sombras',
    ],

    'buttons' => [
        'title' => 'Botones',
        'small' => 'Pequeño',
        'medium' => 'Mediano',
        'large' => 'Grande',
        'with_icon' => 'Con icono',
        'disabled' => 'Deshabilitado',
        'processing' => 'Procesando',
        'as_link' => 'como enlace',
        'link_button' => 'Botón de enlace',
        'disabled_link' => 'Enlace deshabilitado',
        'icon_only' => 'solo icono',
        'retry_payment' => 'Reintentar el pago',
        'delete_card' => 'Eliminar la tarjeta',
        'settings' => 'Configuración',
        'guard_title' => 'Protección contra doble envío',
        'guard_lead' => 'El siguiente formulario usa data-prevent-double-submit. El primer envío pone el botón en estado de carga (sigue siendo enfocable, con aria-disabled y aria-busy); los envíos posteriores se ignoran.',
        'pay_now' => 'Pagar ahora',
        'processing_payment' => 'Procesando el pago',
    ],

    'inputs' => [
        'title' => 'Campos de entrada',
        'email' => 'Correo electrónico',
        'email_placeholder' => 'usted@ejemplo.com',
        'card_number' => 'Número de tarjeta',
        'card_hint' => '16 dígitos, sin espacios.',
        'amount' => 'Importe',
        'amount_error' => 'El importe debe ser mayor que 20,00.',
        'disabled' => 'Deshabilitado',
        'not_editable' => 'No editable',
        'price' => 'Precio',
        'price_hint' => 'Importe que se cobra al cliente.',
        'fee' => 'Comisión de procesamiento (solo lectura)',
    ],

    'badges' => [
        'title' => 'Insignias',
        'draft' => 'Borrador',
        'paid' => 'Pagado',
        'pending' => 'Pendiente',
        'declined' => 'Rechazado',
        'refunded' => 'Reembolsado',
    ],

    'money' => [
        'title' => 'Dinero',
        'amounts' => 'Importes',
        'amounts_lead' => 'Se formatean en el idioma actual, salvo que se fuerce una configuración regional.',
        'payment' => 'Pago',
        'refund' => 'Reembolso',
        'fee_forced' => 'Comisión (EUR, de_DE forzado)',
        'minor_units' => 'Unidades menores (CLP, es_CL forzado)',
        'balance' => 'Saldo (sin signo)',
        'status' => 'Estado del pago',
        'dates' => 'Fechas',
        'created' => 'Creado',
    ],

    'alerts' => [
        'title' => 'Alertas',
        'success_title' => 'Pago recibido',
        'success_body' => 'El comprobante se envió al cliente.',
        'warning_title' => 'Verificación pendiente',
        'warning_body' => 'Es posible que se requiera información adicional.',
        'error_title' => 'Pago rechazado',
        'error_body' => 'El emisor de la tarjeta rechazó la transacción.',
        'info_body' => 'Las liquidaciones se procesan todos los días hábiles.',
    ],

    'cards' => [
        'title' => 'Tarjetas',
        'default' => 'Tarjeta predeterminada',
        'default_body' => 'Superficie, borde y shadow-xs.',
        'elevated' => 'Tarjeta elevada',
        'elevated_body' => 'shadow-md y relleno amplio.',
        'with_slots' => 'Con encabezado y pie',
        'with_slots_body' => 'Los slots de encabezado y pie comparten el relleno de la tarjeta.',
        'cancel' => 'Cancelar',
        'confirm' => 'Confirmar',
    ],

    'icons' => [
        'title' => 'Iconos',
        'secure' => 'Seguro',
        'meaningful' => 'Icono significativo con una etiqueta accesible.',
    ],

    'feature' => [
        'title' => 'Destacado (decorativo)',
        'body' => 'Color protagonista de la marca. Solo decorativo: nunca para botones ni enlaces.',
    ],

];
