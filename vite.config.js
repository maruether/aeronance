import { copyFileSync, mkdirSync } from 'node:fs';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',

                // Der QR-Scanner. Eigener Einstiegspunkt, damit der Polyfill
                // nur auf den Seiten geladen wird, die wirklich scannen --
                // er bringt einen WASM-Dekoder mit, und der hat auf einer
                // Bestandsliste nichts verloren.
                'resources/js/scanner.js',

                // The admin panel's own stylesheet. Without it Filament serves
                // its prebuilt CSS, which contains no utility classes -- and
                // every Tailwind class in our panel views is dead. See the file.
                'resources/css/filament/admin/theme.css',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),

        /*
         * Der WebAssembly-Teil des QR-Dekoders wird MITGELIEFERT statt aus
         * einem CDN geholt -- siehe die Begruendung in resources/js/scanner.js.
         * Als eigenes Plugin und nicht als npm-Skript, damit es niemand
         * vergessen kann: Wer baut, hat die Datei danach.
         */
        {
            name: 'aeronance-zxing-wasm',
            buildStart() {
                mkdirSync('public/wasm', { recursive: true });
                copyFileSync(
                    'node_modules/zxing-wasm/dist/reader/zxing_reader.wasm',
                    'public/wasm/zxing_reader.wasm',
                );
            },
        },
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
