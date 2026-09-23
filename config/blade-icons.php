<?php

/*
 * Overrides for blade-ui-kit/blade-icons. Only the keys listed here replace the
 * package defaults (Laravel merges package config shallowly).
 *
 * Icons are rendered through the design-system <x-icon> atom
 * (resources/views/components/icon.blade.php), which calls svg() directly.
 */

return [

    'components' => [

        // Do not register one Blade component per icon (<x-heroicon-o-bell />).
        // Nothing uses them, and skipping ~1,300 registrations keeps boot fast.
        'disabled' => true,

        // Do not register the package's <x-icon> class component: that name
        // belongs to the design-system atom.
        'default' => false,

    ],

];
