<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('renders the welcome page', function (): void {
    get('/')->assertOk();
});
