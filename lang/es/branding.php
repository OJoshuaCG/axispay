<?php

declare(strict_types=1);

return [

    'page' => [
        'title' => 'Marca',
        'subheading' => 'El logo de la plataforma y cómo se muestra la marca en los paneles y en la página de pago.',
    ],

    'logo' => [
        'heading' => 'Logo de la plataforma',
        'description' => 'Se muestra en ambos paneles (incluidas las páginas de inicio de sesión y de verificación en dos pasos) y en la línea "Con la tecnología de" de la página de pago. Los correos muestran siempre solo el nombre.',
    ],

    'display' => [
        'heading' => 'Qué muestra la marca',
        'description' => 'El nombre se mantiene siempre en los títulos de página, la aplicación de autenticación y los correos, y lo leen los lectores de pantalla.',
        'no_logo' => 'Aún no hay logo, así que se muestra el nombre, elija lo que elija aquí.',
    ],

    'mode' => [
        'logo_and_name' => 'Logo y nombre',
        'logo_only' => 'Solo el logo',
        'name_only' => 'Solo el nombre',
    ],

    'variant' => [
        'light' => 'Logo para modo claro',
        'dark' => 'Logo para modo oscuro',
    ],

    'fields' => [
        'mode' => 'Mostrar',
        'mode_help' => 'Sin logo, siempre se muestra el nombre.',
        'variant' => 'Logo',
        'variant_help' => 'El logo para modo oscuro es opcional; sin él, en modo oscuro se usa el logo para modo claro.',
        'file' => 'Imagen',
        'file_help' => 'PNG, JPEG o WebP, hasta :max_mb MB y :max_px × :max_px píxeles. No se acepta SVG.',
        'remove_light' => 'Logo para modo claro (también elimina el logo para modo oscuro)',
    ],

    'actions' => [
        'upload' => 'Subir logo',
        'upload_heading' => 'Subir un logo',
        'upload_help' => 'La imagen se revisa y se convierte a PNG; se eliminan los datos ocultos, como la ubicación o los datos de la cámara. Hasta :max_mb MB y :max_px × :max_px píxeles.',
        'upload_submit' => 'Subir',
        'display_mode' => 'Cambiar visualización',
        'display_mode_heading' => 'Qué muestra la marca',
        'save' => 'Guardar',
        'remove' => 'Eliminar logo',
        'remove_heading' => 'Eliminar un logo',
        'remove_help' => 'Sin logo, se muestra el nombre en todas partes.',
    ],

    'favicon' => [
        'heading' => 'Favicon',
        'description' => 'El icono pequeño de las pestañas del navegador, los marcadores y los accesos directos, en ambos paneles y en la página de pago. Sin uno, se usa el icono predeterminado.',
        'default_in_use' => 'Se está usando el favicon predeterminado.',
        'upload' => 'Subir favicon',
        'upload_heading' => 'Subir un favicon',
        'upload_help' => 'Lo mejor es una imagen cuadrada; cualquier otra forma se centra sobre un cuadrado transparente. La imagen se revisa y se convierte a PNG en tres tamaños, sin datos ocultos. PNG, JPEG o WebP, hasta :max_mb MB, de :min_px × :min_px a :max_px × :max_px píxeles.',
        'file_help' => 'PNG, JPEG o WebP, hasta :max_mb MB, de :min_px × :min_px a :max_px × :max_px píxeles. No se aceptan SVG ni ICO.',
        'remove' => 'Eliminar favicon',
        'remove_heading' => 'Eliminar el favicon',
        'remove_help' => 'Se volverá a usar el favicon predeterminado.',
        'updated' => 'Favicon actualizado.',
        'removed' => 'Favicon eliminado.',
        'preview_alt' => 'Favicon de :size × :size píxeles',
        'size' => [
            '32' => ':px × :px · pestaña del navegador',
            '180' => ':px × :px · dispositivos Apple',
            '192' => ':px × :px · Android y pantalla de inicio',
        ],
    ],

    'preview' => [
        'alt' => ':variant de :name',
        'empty' => 'Sin logo',
        'dark_fallback' => 'No hay logo para modo oscuro: en modo oscuro se usa el logo para modo claro.',
    ],

    'notifications' => [
        'updated' => ':variant actualizado.',
        'removed' => ':variant eliminado.',
        'mode_changed' => 'Ahora la marca muestra: :mode.',
    ],

    'errors' => [
        'empty' => 'Elija una imagen para subir.',
        'too_large' => 'La imagen supera :max_mb MB.',
        'unsupported_type' => 'Solo se aceptan imágenes PNG, JPEG o WebP.',
        'unreadable' => 'No se pudo leer la imagen. Compruebe que el archivo no esté dañado y que su contenido corresponda a su tipo.',
        'dimensions_too_large' => 'La imagen supera :max_px × :max_px píxeles.',
        'dimensions_too_small' => 'La imagen es menor de :min_px × :min_px píxeles.',
        'reauthentication_required' => 'Confirme su identidad para continuar.',
    ],

    // ADR-0056 parte B: el logo del comercio, página "Marca" del panel del tenant.
    'tenant' => [
        'navigation_group' => 'Configuración',
        'title' => 'Marca',
        'subheading' => 'El logo de tu empresa en las páginas de pago.',
        'heading' => 'Logo de la empresa',
        'description' => 'Se muestra arriba en tus páginas de pago, centrado y grande, con el nombre de tu empresa como texto alternativo. Solo lo ven los pagadores: este panel siempre muestra el logo de la plataforma. Sin logo, los pagadores ven el nombre de tu empresa.',
        'preview_light' => 'Tema claro',
        'preview_dark' => 'Tema oscuro',
        'preview_alt' => 'Tu logo sobre un fondo de :theme',
        'preview_empty' => 'Sin logo: los pagadores ven el nombre de tu empresa.',
        'dark_fallback' => 'No hay logo para el tema oscuro: en el tema oscuro tu logo se muestra sobre una placa clara, como aquí.',
        'variant' => [
            'light' => 'Logo',
            'dark' => 'Logo para el tema oscuro',
        ],
        'actions' => [
            'upload_light' => 'Subir logo',
            'replace_light' => 'Reemplazar logo',
            'upload_dark' => 'Subir logo para el tema oscuro',
            'replace_dark' => 'Reemplazar logo para el tema oscuro',
            'upload_help' => 'La imagen se revisa y se convierte a PNG, reducida para caber en :box_width × :box_height píxeles (nunca se amplía); se eliminan los datos ocultos, como la ubicación o los datos de la cámara. PNG, JPEG o WebP, hasta :max_mb MB y :max_px × :max_px píxeles. No se acepta SVG.',
            'upload_dark_help' => 'Opcional: una versión de tu logo para fondos oscuros (por ejemplo, con texto blanco). Sin ella, en el tema oscuro tu logo se muestra sobre una placa clara. La imagen se revisa y se convierte a PNG, reducida para caber en :box_width × :box_height píxeles (nunca se amplía), sin datos ocultos. PNG, JPEG o WebP, hasta :max_mb MB y :max_px × :max_px píxeles. No se acepta SVG.',
            'remove_light' => 'Eliminar logo',
            'remove_dark' => 'Eliminar logo para el tema oscuro',
            'remove_light_heading' => 'Eliminar tu logo',
            'remove_light_help' => 'También se elimina el logo para el tema oscuro. Los pagadores verán el nombre de tu empresa.',
            'remove_dark_heading' => 'Eliminar el logo para el tema oscuro',
            'remove_dark_help' => 'En el tema oscuro, tu logo se mostrará sobre una placa clara.',
        ],
        'notifications' => [
            'updated' => ':variant actualizado. Los pagadores ya lo ven en tus páginas de pago.',
            'removed' => ':variant eliminado.',
        ],
    ],

];
