import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';
import vue from '@vitejs/plugin-vue';
import AutoImport from 'unplugin-auto-import/vite';
import Components from 'unplugin-vue-components/vite';
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers';
import path from 'path';

// WordPress dependencies that should be externalized for the block editor
const wpExternals = {
  '@wordpress/blocks': 'wp.blocks',
  '@wordpress/block-editor': 'wp.blockEditor',
  '@wordpress/components': 'wp.components',
  '@wordpress/element': 'wp.element',
  '@wordpress/i18n': 'wp.i18n',
  '@wordpress/data': 'wp.data',
  'react': 'React',
  'react-dom': 'ReactDOM',
};

/**
 * One pass, one public entry.
 *
 * There were two: app.js and a second front end for classic themes, which
 * shared ajax/session/validate/autosize with it. Rollup hoists a module
 * reached from two entries into a shared chunk, and WordPress enqueues these
 * as plain script tags with no modulepreload - so the browser fetched the
 * entry, parsed it, discovered a bare `import` and only then went back for
 * the rest. A serial round trip on every page with comments, to share code
 * between two front ends that were never on the same page. The second entry
 * built apart to avoid it.
 *
 * Both themes now load the same app, so there is nothing to share and
 * nothing to split. `emptyOutDir: false` stays: @wordpress/scripts writes
 * the block into the same dist/, and build.sh clears it instead.
 */

export default defineConfig({
  plugins: [
    svelte(),
    vue(),
    AutoImport({
      resolvers: [ElementPlusResolver()],
    }),
    Components({
      resolvers: [ElementPlusResolver()],
      directives: false
    }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'resources/admin/src')
    }
  },
  build: {
    outDir: 'dist',
    emptyOutDir: false,
    rollupOptions: {
      input: {
        app: path.resolve(__dirname, 'resources/js/app.js'),
        admin_app: path.resolve(__dirname, 'resources/admin/src/app.js'),
      },
      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) return 'css/[name][extname]';
          return 'assets/[name]-[hash][extname]';
        }
      }
    }
  }
});
