import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/gallery/index.ts', 'resources/js/maps/listing.js', 'resources/js/maps/page.js', 'resources/js/games/edit.ts'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@/lib': fileURLToPath(new URL('./resources/js/gallery/vendor/lib', import.meta.url)),
            '@lingui/core/macro': fileURLToPath(new URL('./resources/js/gallery/shims/lingui-macro.ts', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
