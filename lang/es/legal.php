<?php

declare(strict_types=1);

/*
| Textos legales en los paneles (ADR-0056): el aviso de privacidad y los
| términos del comercio (panel del tenant) y los de la plataforma (panel de
| la plataforma). El texto para el pagador vive en checkout.php.
*/

return [

    'navigation' => [
        'group' => 'Configuración',
    ],

    'page' => [
        'title' => 'Legal',
        'tenant_subheading' => 'Su aviso de privacidad y sus términos y condiciones, que los pagadores ven en sus páginas de pago.',
        'platform_subheading' => 'El aviso de privacidad y los términos y condiciones de la plataforma, que se muestran en la página legal pública enlazada desde cada página de pago.',
    ],

    'kind' => [
        'privacy' => 'Aviso de privacidad',
        'terms' => 'Términos y condiciones',
    ],

    'format' => [
        'text' => 'Escribir el texto aquí',
        'url' => 'Enlazar a una página',
    ],

    'sections' => [
        'tenant_privacy' => 'Necesario para pedir datos a los pagadores: sin él, la página de pago no recaba ningún dato del pagador. Los pagadores lo abren desde la página de pago.',
        'tenant_terms' => 'Opcional. Los pagadores lo abren desde la página de pago.',
        'platform_privacy' => 'Se muestra en la página legal pública del dominio de pagos.',
        'platform_terms' => 'Se muestra en la página legal pública del dominio de pagos.',
    ],

    'status' => [
        'not_set' => 'Sin configurar.',
        'link' => 'Publicado como enlace:',
        'text' => 'Publicado como texto. Vista previa:',
        'no_privacy_warning' => 'Sin aviso de privacidad, sus páginas de pago no piden datos a los pagadores, aunque el link esté configurado para recabarlos.',
    ],

    'fields' => [
        'format' => 'Cómo publicarlo',
        'body' => 'Texto',
        'body_help' => 'Markdown sencillo: una línea en blanco separa los párrafos; # para títulos, - para listas, **negritas**, [texto](https://ejemplo.com) para enlaces. No se permite HTML. Hasta :max caracteres.',
        'url' => 'Dirección de la página',
        'url_help' => 'La dirección completa, que empiece con https://. Los pagadores la abren en una pestaña nueva.',
    ],

    'actions' => [
        'edit' => 'Editar',
        'set' => 'Agregar',
        'edit_heading' => 'Editar: :document',
        'save' => 'Guardar',
        'remove' => 'Eliminar',
        'remove_heading' => 'Eliminar: :document',
        'remove_help' => 'Los pagadores ya no podrán consultar este documento.',
        'remove_privacy_help' => 'Los pagadores ya no podrán consultar este documento y sus páginas de pago dejarán de pedir datos del pagador.',
    ],

    'notifications' => [
        'saved' => 'Se guardó: :document.',
        'removed' => 'Se eliminó: :document.',
    ],

    'errors' => [
        'empty_body' => 'Escriba el texto del documento.',
        'body_too_long' => 'Use como máximo :max caracteres.',
        'invalid_url' => 'Escriba una dirección completa que empiece con http:// o https://, de hasta :max_url caracteres.',
        'reauthentication_required' => 'Confirme su contraseña para cambiar los documentos legales.',
    ],

];
