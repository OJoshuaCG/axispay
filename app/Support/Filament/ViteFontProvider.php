<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\FontProviders\Contracts\FontProvider;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;

/**
 * Serves the self-hosted font build (laravel-vite-plugin `fonts`, see
 * vite.config.js) to the Filament panels instead of a font CDN: the same
 * preload links and @font-face rules the `@fonts` Blade directive prints, for
 * every configured family (Mukta and Geist Mono), whatever `$family` Filament
 * passes. It is registered once, through `->font()`; registering it again
 * through `->monoFont()` would print the same rules twice. The panel theme
 * maps `--font-sans` to `--font-mukta` and `--font-mono` to the numeric
 * (Geist Mono) stack (ADR-0042).
 */
final class ViteFontProvider implements FontProvider
{
    public function getHtml(string $family, ?string $url = null): Htmlable
    {
        return new HtmlString(Vite::fonts()->toHtml());
    }
}
