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

    'favicon' => [
        'heading' => 'Favicon',
        'description' => 'The small icon of browser tabs, bookmarks and home-screen shortcuts, in both panels and the payment page. Without one, the default icon is used.',
        'default_in_use' => 'The default favicon is in use.',
        'upload' => 'Upload favicon',
        'upload_heading' => 'Upload a favicon',
        'upload_help' => 'A square image works best; any other shape is centered on a transparent square. The image is checked and converted to PNG in three sizes, without hidden data. PNG, JPEG or WebP, up to :max_mb MB, from :min_px × :min_px to :max_px × :max_px pixels.',
        'file_help' => 'PNG, JPEG or WebP, up to :max_mb MB, from :min_px × :min_px to :max_px × :max_px pixels. SVG and ICO are not accepted.',
        'remove' => 'Remove favicon',
        'remove_heading' => 'Remove the favicon',
        'remove_help' => 'The default favicon will be used again.',
        'updated' => 'Favicon updated.',
        'removed' => 'Favicon removed.',
        'preview_alt' => 'Favicon at :size × :size pixels',
        'size' => [
            '32' => ':px × :px · browser tab',
            '180' => ':px × :px · Apple devices',
            '192' => ':px × :px · Android and home screen',
        ],
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
        'dimensions_too_small' => 'The image is smaller than :min_px × :min_px pixels.',
        'reauthentication_required' => 'Confirm your identity to continue.',
    ],

    // ADR-0056 part B: the merchant's logo, "Brand" page of the tenant panel.
    'tenant' => [
        'navigation_group' => 'Settings',
        'title' => 'Brand',
        'subheading' => 'Your company logo on the payment pages.',
        'heading' => 'Company logo',
        'description' => 'Shown at the top of your payment pages, centered and large, with your company name as its text alternative. Only payers see it: this panel always shows the platform logo. Without a logo, payers see your company name.',
        'preview_light' => 'Light theme',
        'preview_dark' => 'Dark theme',
        'preview_alt' => 'Your logo on a :theme background',
        'preview_empty' => 'No logo: payers see your company name.',
        'dark_fallback' => 'There is no dark theme logo: in dark theme your logo is shown on a light plate, as here.',
        'variant' => [
            'light' => 'Logo',
            'dark' => 'Dark theme logo',
        ],
        'actions' => [
            'upload_light' => 'Upload logo',
            'replace_light' => 'Replace logo',
            'upload_dark' => 'Upload dark theme logo',
            'replace_dark' => 'Replace dark theme logo',
            'upload_help' => 'The image is checked and converted to PNG, reduced to fit :box_width × :box_height pixels (never enlarged); hidden data such as location or camera details is removed. PNG, JPEG or WebP, up to :max_mb MB and :max_px × :max_px pixels. SVG is not accepted.',
            'upload_dark_help' => 'Optional: a version of your logo for dark backgrounds (for example, with white text). Without it, your logo is shown on a light plate in dark theme. The image is checked and converted to PNG, reduced to fit :box_width × :box_height pixels (never enlarged), without hidden data. PNG, JPEG or WebP, up to :max_mb MB and :max_px × :max_px pixels. SVG is not accepted.',
            'remove_light' => 'Remove logo',
            'remove_dark' => 'Remove dark theme logo',
            'remove_light_heading' => 'Remove your logo',
            'remove_light_help' => 'The dark theme logo is removed too. Payers will see your company name instead.',
            'remove_dark_heading' => 'Remove the dark theme logo',
            'remove_dark_help' => 'In dark theme, your logo will be shown on a light plate.',
        ],
        'notifications' => [
            'updated' => ':variant updated. Payers see it on your payment pages now.',
            'removed' => ':variant removed.',
        ],
    ],

];
