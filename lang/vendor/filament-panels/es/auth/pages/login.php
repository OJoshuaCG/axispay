<?php

declare(strict_types=1);

/*
 * Overrides of Filament's Spanish sign-in strings (merged over the package
 * file, only these keys change): the usual "Iniciar sesión" wording instead of
 * "Entre a su cuenta" / "Entrar" (ADR-0044).
 */
return [

    'heading' => 'Inicie sesión',

    'form' => [

        'actions' => [

            'authenticate' => [
                'label' => 'Iniciar sesión',
            ],

        ],

    ],

];
