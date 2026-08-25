import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                // Bangla headline face — serif, print authority
                bunny('Noto Serif Bengali', { weights: [500, 600, 700] }),
                // Bangla body face — screen legibility
                bunny('Noto Sans Bengali', { weights: [400, 500, 600, 700] }),
                // Latin / numerals / UI chrome
                bunny('Inter', { weights: [400, 500, 600, 700] }),
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
