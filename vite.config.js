import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

/*
 * WOFF2 files of a pinned @fontsource package, latin subset, one per weight.
 *
 * Why `local()` and not the plugin's `fontsource()` provider: in
 * laravel-vite-plugin 3.2.0, `fontsource()` emits the woff2 and the woff file
 * of each weight as two separate @font-face rules with identical descriptors.
 * The last rule (woff) wins, so browsers downloaded every woff AND the
 * preloaded woff2, which then went unused. Pointing `local()` at the woff2
 * files gives one rule and one file per weight (every supported browser reads
 * woff2). The files still come from the pinned packages in package.json.
 */
const fontsourceWoff2 = (slug, weights) =>
    weights.map((weight) => ({
        src: `node_modules/@fontsource/${slug}/files/${slug}-latin-${weight}-normal.woff2`,
        weight,
    }));

export default defineConfig({
    plugins: [
        laravel({
            // The panel theme is a separate entry: Filament loads it with ->viteTheme().
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/theme.css'],
            refresh: true,
            // Mukta (text) and Geist Mono (currency and numeric data, ADR-0042) are
            // self-hosted: font files are read from the pinned @fontsource/* packages
            // at build time and emitted into public/build. No third-party font CDN
            // is contacted at build time or at runtime.
            //
            // Subset: latin only. It covers English and Spanish (U+0000-00FF incl.
            // á é í ó ú ü ñ ¿ ¡), €, and U+2212 MINUS SIGN used by <x-amount>.
            // latin-ext is not loaded: neither language needs it.
            //
            // Preload only what the first paint renders on nearly every page: Mukta
            // 400 (body) and 600 (headings, buttons). Geist Mono is not preloaded:
            // amounts are a small part of most pages, and with `display: swap` the
            // system monospace fallback (same 0.6em digit width) keeps the late
            // swap nearly shift-free.
            //
            // optimizedFallbacks uses `fontaine` (devDependency; an optional peer of
            // laravel-vite-plugin) to emit a metric-matched "Mukta fallback" face
            // (local Arial with ascent/descent/line-gap/size-adjust overrides), so
            // swapping in Mukta causes almost no layout shift. Removing fontaine
            // silently disables this; keep them together.
            fonts: [
                local('Mukta', {
                    variants: fontsourceWoff2('mukta', [400, 500, 600, 700]),
                    display: 'swap',
                    preload: [{ weight: 400 }, { weight: 600 }],
                    optimizedFallbacks: true,
                }),
                // 400: every amount; 600: emphasized totals (font-semibold). A
                // font-medium amount resolves to 400 and font-bold to 600 (CSS
                // font matching), so no faux-bold is synthesized.
                //
                // No optimized fallback: fontaine 0.8 reports no category for Geist
                // Mono, so the plugin would emit an Arial face at size-adjust 136%,
                // a proportional font in front of the monospace stack. The token
                // stack (--font-numeric: ui-monospace, SFMono-Regular, Menlo,
                // Consolas, monospace) already matches Geist Mono's 0.6em advance.
                local('Geist Mono', {
                    variants: fontsourceWoff2('geist-mono', [400, 600]),
                    display: 'swap',
                    preload: false,
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
