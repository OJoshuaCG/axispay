<?php

declare(strict_types=1);

namespace App\Support\Filament;

use RuntimeException;

/**
 * Builds Filament's color palettes from the design-system primitives in
 * resources/css/tokens/primitives.css, so the panels use our colors without a
 * second copy of any value (ADR-0030).
 *
 * Filament needs full 50-950 scales and computes label contrast from them in
 * PHP, which is why the palettes are registered here (Filament converts the
 * hex values to OKLCH) instead of overriding its CSS variables. Where our
 * source palette has no step (950 everywhere, partial amber/red scales), the
 * nearest defined primitive is used; the maps below are the single place to
 * change that.
 */
final class DesignTokenPalette
{
    private const string PRIMITIVES = 'resources/css/tokens/primitives.css';

    /**
     * Filament shade => design-system primitive, per Filament color.
     *
     * @var array<string, array<int, string>>
     */
    public const array MAP = [
        'primary' => [
            50 => 'blue-50', 100 => 'blue-100', 200 => 'blue-200', 300 => 'blue-300', 400 => 'blue-400',
            500 => 'blue-500', 600 => 'blue-600', 700 => 'blue-700', 800 => 'blue-800', 900 => 'blue-900', 950 => 'blue-900',
        ],
        'info' => [
            50 => 'blue-50', 100 => 'blue-100', 200 => 'blue-200', 300 => 'blue-300', 400 => 'blue-400',
            500 => 'blue-500', 600 => 'blue-600', 700 => 'blue-700', 800 => 'blue-800', 900 => 'blue-900', 950 => 'blue-900',
        ],
        'success' => [
            50 => 'green-50', 100 => 'green-100', 200 => 'green-200', 300 => 'green-300', 400 => 'green-400',
            500 => 'green-500', 600 => 'green-600', 700 => 'green-700', 800 => 'green-800', 900 => 'green-900', 950 => 'green-900',
        ],
        // Light surfaces use the low steps, dark surfaces (page neutral-900,
        // cards neutral-700/800) the high ones, matching semantic.css.
        'gray' => [
            50 => 'neutral-50', 100 => 'neutral-100', 200 => 'neutral-200', 300 => 'neutral-300', 400 => 'neutral-400',
            500 => 'neutral-500', 600 => 'neutral-550', 700 => 'neutral-600', 800 => 'neutral-700', 900 => 'neutral-800', 950 => 'neutral-900',
        ],
        'warning' => [
            50 => 'amber-50', 100 => 'amber-50', 200 => 'amber-400', 300 => 'amber-400', 400 => 'amber-400',
            500 => 'amber-400', 600 => 'amber-700', 700 => 'amber-700', 800 => 'amber-700', 900 => 'amber-700', 950 => 'amber-700',
        ],
        'danger' => [
            50 => 'red-50', 100 => 'red-50', 200 => 'red-400', 300 => 'red-400', 400 => 'red-400',
            500 => 'red-400', 600 => 'red-700', 700 => 'red-700', 800 => 'red-800', 900 => 'red-900', 950 => 'red-900',
        ],
    ];

    /** @var array<string, string>|null */
    private static ?array $primitives = null;

    /**
     * @return array<string, array<int, string>> Filament color => shade => hex
     */
    public static function filamentColors(): array
    {
        $primitives = self::primitives();
        $colors = [];

        foreach (self::MAP as $color => $shades) {
            foreach ($shades as $shade => $token) {
                $colors[$color][$shade] = $primitives[$token]
                    ?? throw new RuntimeException("Design token [--color-{$token}] is not defined in ".self::PRIMITIVES.'.');
            }
        }

        return $colors;
    }

    /**
     * @return array<string, string> token name (e.g. `blue-500`) => hex
     */
    public static function primitives(): array
    {
        if (self::$primitives !== null) {
            return self::$primitives;
        }

        $css = @file_get_contents(base_path(self::PRIMITIVES));

        if ($css === false) {
            throw new RuntimeException('Cannot read '.self::PRIMITIVES.'.');
        }

        preg_match_all('/--color-([a-z]+-\d+)\s*:\s*(#[0-9a-fA-F]{6})\b/', $css, $matches, PREG_SET_ORDER);

        $primitives = [];

        foreach ($matches as $match) {
            $primitives[$match[1]] = strtolower($match[2]);
        }

        return self::$primitives = $primitives;
    }
}
