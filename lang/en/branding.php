<?php

declare(strict_types=1);

return [

    'page' => [
        'title' => 'Branding',
        'subheading' => 'The platform logo and how the brand is shown in the panels and the payment page.',
    ],

    'logo' => [
        'heading' => 'Platform logo',
        'description' => 'Shown in both panels (including sign-in and two-factor pages) and in the "Powered by" line of the payment page. E-mails always show the name only.',
    ],

    'display' => [
        'heading' => 'What the brand shows',
        'description' => 'The name always stays in page titles, the authenticator app and e-mails, and is read by screen readers.',
        'no_logo' => 'There is no logo yet, so the name is shown whatever you choose here.',
    ],

    'mode' => [
        'logo_and_name' => 'Logo and name',
        'logo_only' => 'Logo only',
        'name_only' => 'Name only',
    ],

    'variant' => [
        'light' => 'Light mode logo',
        'dark' => 'Dark mode logo',
    ],

    'fields' => [
        'mode' => 'Show',
        'mode_help' => 'Without a logo the name is always shown.',
        'variant' => 'Logo',
        'variant_help' => 'The dark mode logo is optional; without it the light mode logo is used in dark mode.',
        'file' => 'Image',
        'file_help' => 'PNG, JPEG or WebP, up to :max_mb MB and :max_px × :max_px pixels. SVG is not accepted.',
        'remove_light' => 'Light mode logo (also removes the dark mode logo)',
    ],

    'actions' => [
        'upload' => 'Upload logo',
        'upload_heading' => 'Upload a logo',
        'upload_help' => 'The image is checked and converted to PNG; hidden data such as location or camera details is removed. Up to :max_mb MB and :max_px × :max_px pixels.',
        'upload_submit' => 'Upload',
        'display_mode' => 'Change display',
        'display_mode_heading' => 'What the brand shows',
        'save' => 'Save',
        'remove' => 'Remove logo',
        'remove_heading' => 'Remove a logo',
        'remove_help' => 'Without a logo the name is shown everywhere.',
    ],

    'preview' => [
        'alt' => ':variant of :name',
        'empty' => 'No logo',
        'dark_fallback' => 'There is no dark mode logo: the light mode logo is used in dark mode.',
    ],

    'notifications' => [
        'updated' => ':variant updated.',
        'removed' => ':variant removed.',
        'mode_changed' => 'The brand now shows: :mode.',
    ],

    'errors' => [
        'empty' => 'Choose an image to upload.',
        'too_large' => 'The image is larger than :max_mb MB.',
        'unsupported_type' => 'Only PNG, JPEG or WebP images are accepted.',
        'unreadable' => 'The image could not be read. Check that the file is not damaged and that its content matches its type.',
        'dimensions_too_large' => 'The image is larger than :max_px × :max_px pixels.',
        'reauthentication_required' => 'Confirm your identity to continue.',
    ],

];
