<?php

declare(strict_types=1);

use App\Support\Filament\DesignTokenPalette;

it('builds every Filament palette from design-system primitives', function (): void {
    $colors = DesignTokenPalette::filamentColors();
    $primitives = DesignTokenPalette::primitives();

    expect(array_keys($colors))->toBe(['primary', 'info', 'success', 'gray', 'warning', 'danger']);

    foreach ($colors as $palette) {
        expect(array_keys($palette))->toBe([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]);

        foreach ($palette as $hex) {
            expect($hex)->toMatch('/^#[0-9a-f]{6}$/')
                ->and($primitives)->toContain($hex);
        }
    }

    expect($colors['primary'][600])->toBe($primitives['blue-600'])
        ->and($colors['gray'][950])->toBe($primitives['neutral-900']);
});
