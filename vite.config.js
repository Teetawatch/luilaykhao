import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    build: {
        rollupOptions: {
            output: {
                // Split heavy, rarely-changing vendor libs into their own
                // cacheable chunks. The admin-only editors (tiptap, cropperjs)
                // are only pulled in by lazy admin routes, so customers never
                // download them.
                manualChunks(id) {
                    if (!id.includes('node_modules')) return;
                    if (id.includes('@tiptap') || id.includes('cropperjs')) return 'editor';
                    if (id.includes('laravel-echo') || id.includes('pusher-js')) return 'realtime';
                    if (id.includes('sweetalert2')) return 'swal';
                    if (id.includes('/vue/') || id.includes('vue-router') || id.includes('pinia') || id.includes('@unhead')) return 'vue';
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
