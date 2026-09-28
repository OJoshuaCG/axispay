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
        'reauthentication_required' => 'Confirme su identidad para continuar.',
    ],

];
