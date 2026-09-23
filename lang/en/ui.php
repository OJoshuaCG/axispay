<?php

/*
 * Shared interface chrome: layout, theme and language controls, and the
 * default strings of design-system components. Page copy lives in a file named
 * after the page (welcome.php, ...); payment vocabulary lives in payments.php.
 */
return [

    'layout' => [
        'skip_to_content' => 'Skip to main content',
        'site_controls' => 'Display settings',
    ],

    'theme' => [
        'label' => 'Color theme',
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'System',
    ],

    'language' => [
        'label' => 'Language',
    ],

    'button' => [
        'loading' => 'Processing',
    ],

    // Visually hidden prefixes: severity must not depend on color or icon.
    'alert' => [
        'success' => 'Success:',
        'warning' => 'Warning:',
        'error' => 'Error:',
        'info' => 'Information:',
    ],

    'amount' => [
        // Screen-reader text for the em dash shown when a value is invalid.
        'unavailable' => 'Amount unavailable',
    ],

];
