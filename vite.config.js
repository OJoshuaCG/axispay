import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { fontsource } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Jost is self-hosted: font files are read from the pinned @fontsource/jost
            // package at build time and emitted into public/build. No third-party
            // font CDN is contacted at build time or at runtime.
            //
            // optimizedFallbacks uses `fontaine` (devDependency; an optional peer of
            // laravel-vite-plugin) to emit a metric-matched "Jost fallback" face
            // (local Arial with ascent/descent/line-gap/size-adjust overrides), so
            // swapping in Jost after `display: swap` causes almost no layout shift.
            // Removing fontaine silently disables this; keep them together.
            fonts: [
                fontsource('Jost', {
                    weights: [400, 500, 600, 700],
                    subsets: ['latin'],
                    display: 'swap',
                    preload: [{ weight: 400 }, { weight: 600 }],
                    optimizedFallbacks: true,
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
