<?php

declare(strict_types=1);

namespace App\Support\Filament;

use Filament\FontProviders\Contracts\FontProvider;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;

/**
 * Serves the self-hosted Jost build (laravel-vite-plugin `fonts`, see
 * vite.config.js) to the Filament panels instead of a font CDN: the same
 * preload links and @font-face rules the `@fonts` Blade directive prints.
 * The panel theme maps `--font-sans` to `--font-jost`.
 */
final class ViteFontProvider implements FontProvider
{
    public function getHtml(string $family, ?string $url = null): Htmlable
    {
        return new HtmlString(Vite::fonts()->toHtml());
    }
}
