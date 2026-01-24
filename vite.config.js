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
        'native-comments': path.resolve(__dirname, 'resources/js/native-comments.js'),
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
