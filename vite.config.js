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
 * Two passes, not one, and the reason is the shared chunk it otherwise makes.
 *
 * app.js and native-comments.js both import ajax/session/validate/autosize -
 * deliberately, so the submission rules are written once. Rollup sees a module
 * reached from two entry points and hoists it into a shared chunk rather than
 * duplicating it. But those two entries are the two front ends, and a page
 * loads one or the other; the sharing was theoretical while the cost was real.
 * WordPress enqueues these as plain script tags with no modulepreload, so the
 * browser had to fetch the entry, parse it, discover a bare `import` and only
 * then go back for the rest - a serial round trip on every page with comments,
 * on both front ends, to save 1.7kB on the rare page that renders both.
 *
 * Rollup will not duplicate a module shared between entries, and no output
 * option asks it to, so the entries are built apart instead: native-comments
 * has its own pass and inlines everything it uses. `emptyOutDir: false` is
 * what lets the second pass land beside the first (see build.sh, which clears
 * dist/ itself for exactly this reason).
 *
 * The duplicated ~1.7kB is in two bundles that are never both on a page.
 */
const ENTRIES = {
  main: {
    app: path.resolve(__dirname, 'resources/js/app.js'),
    admin_app: path.resolve(__dirname, 'resources/admin/src/app.js'),
  },
  native: {
    'native-comments': path.resolve(__dirname, 'resources/js/native-comments.js'),
  },
};

export default defineConfig(({ mode }) => ({
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
      input: ENTRIES[mode === 'native' ? 'native' : 'main'],
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
}));
