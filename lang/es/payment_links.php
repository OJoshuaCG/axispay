<?php

declare(strict_types=1);

/*
 * Links de pago (plan 7.5, 9.1, 10.5).
 */
return [

    'singular' => 'link de pago',
    'plural' => 'links de pago',

    'navigation' => [
        'group' => 'Pagos',
    ],

    'page' => [
        'title' => 'Link de pago',
        'subheading' => [
            'test' => 'Modo de prueba: estos links nunca cobran tarjetas reales.',
            'live' => 'Modo real: estos links cobran tarjetas reales.',
        ],
    ],

    'sections' => [
        'summary' => 'Resumen',
        'details' => 'Detalles',
        'more_options' => 'Más opciones',
    ],

    'fields' => [
        'id' => 'ID',
        'link' => 'Link',
        'expires' => 'Vence',
        'expiry' => 'Vigencia',
        'status' => 'Estado',
        'amount' => 'Monto',
        'amount_help' => 'Hasta dos decimales después del punto, por ejemplo 1,500.00.',
        'currency' => 'Moneda',
        'description' => 'Descripción',
        'description_help' => 'La ve el pagador. Texto simple, hasta 500 caracteres.',
        'client_reference_id' => 'Su referencia',
        'client_reference_id_help' => 'Opcional, por ejemplo su número de pedido. El pagador no la ve.',
        'expires_in_hours' => 'Vence en (horas)',
        'expires_in_hours_help' => 'Vacío: :default horas. Máximo :max horas.',
        'locale' => 'Idioma de la página de pago',
        'locale_help' => 'Vacío: el predeterminado de la cuenta.',
        'url' => 'Link para compartir',
        'expires_at' => 'Vence',
        'created_at' => 'Creado',
        'created_via' => 'Creado desde',
        'fx_mode' => 'Conversión de moneda',
        'return_url' => 'URL de retorno',
        'payer_fields' => 'Campos del pagador',
        'metadata' => 'Metadatos',
        'metadata_help' => 'Datos privados para sus sistemas; el pagador nunca los ve. Hasta :max entradas; claves con letras, dígitos, "_" o "-".',
        'metadata_key' => 'Clave',
        'metadata_value' => 'Valor',
        'metadata_add' => 'Agregar entrada',
        'paid_at' => 'Pagado',
        'canceled_at' => 'Cancelado',
        'cancel_reason' => 'Motivo de cancelación',
        'cancel_reason_help' => 'Opcional. Visible en el panel y la API, no para el pagador.',
        'expired_at' => 'Vencido',
        'open_count' => 'Veces abierto',
        'refund_status' => 'Reembolsos',
        'dispute_status' => 'Disputas',
    ],

    'expires_line' => 'Vence :date',

    'callout' => [
        'expired' => 'Venció el :date. Ya no acepta pagos.',
        'canceled' => 'Cancelado el :date. Ya no acepta pagos.',
    ],

    'expiry' => [
        'hours' => ':count horas',
        'days' => ':count días',
        'custom' => 'Personalizado',
    ],

    'validation' => [
        'amount_required' => 'Escriba el monto.',
        'description_required' => 'Escriba una descripción para el pagador.',
        'amount_format' => 'Escriba el monto con hasta dos decimales, por ejemplo 1,500.00.',
    ],

    'url_help' => 'Cualquiera con este link puede ver la descripción y pagar. Compártalo solo con el pagador.',
    'copied' => 'Copiado',
    'copy_failed' => 'No se pudo copiar. Seleccione el texto y cópielo a mano.',

    'status' => [
        'active' => 'Activo',
        'processing' => 'En proceso',
        'paid' => 'Pagado',
        'expired' => 'Vencido',
        'canceled' => 'Cancelado',
    ],

    'fx_mode' => [
        'none' => 'Ninguna',
        'banxico_fix' => 'Tipo de cambio FIX de Banxico',
        'fixed' => 'Tipo de cambio fijo',
    ],

    'refund_status' => [
        'none' => 'Ninguno',
        'partial' => 'Parcial',
        'full' => 'Total',
    ],

    'dispute_status' => [
        'none' => 'Ninguna',
        'open' => 'Abierta',
        'won' => 'Ganada',
        'lost' => 'Perdida',
    ],

    'created_via' => [
        'api' => 'API',
        'panel' => 'Panel',
    ],

    'locale' => [
        'es' => 'Español',
        'en' => 'Inglés',
    ],

    'payer_field' => [
        'email' => 'Correo electrónico',
        'full_name' => 'Nombre completo',
        'phone' => 'Teléfono',
        'company_name' => 'Empresa',
        'billing_address' => 'Dirección de facturación',
        'tax_id' => 'RFC / identificación fiscal',
        'notes' => 'Notas',
    ],

    'payer_requirement' => [
        'hidden' => 'Oculto',
        'optional' => 'Opcional',
        'required' => 'Obligatorio',
    ],

    'empty' => [
        'heading' => 'Aún no hay links de pago',
        'description' => 'Cree un link aquí o desde la API y compártalo con el pagador.',
    ],

    'actions' => [
        'create' => 'Crear link de pago',
        'create_submit' => 'Crear link',
        'create_help' => [
            'test' => 'Modo de prueba: el link no cobrará tarjetas reales.',
            'live' => 'Modo real: el link cobrará una tarjeta real.',
        ],
        'copy_link' => 'Copiar link',
        'copy_id' => 'Copiar ID',
        'copy_link_tooltip' => 'Copia el link para compartirlo con el pagador',
        'cancel' => 'Cancelar link',
        'cancel_heading' => '¿Cancelar este link de pago?',
        'cancel_help' => 'Se cancelará el link de :amount («:description»). El pagador ya no podrá pagarlo. No se puede deshacer.',
        'cancel_keep' => 'Mantener link',
        'cancel_submit' => 'Cancelar link',
    ],

    'notifications' => [
        'created' => 'Link de pago creado',
        'canceled' => 'Link de pago cancelado',
    ],

];
