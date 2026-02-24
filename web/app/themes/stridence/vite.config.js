import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  root: resolve(__dirname, 'src'),
  base: '/app/themes/stridence/dist/',

  build: {
    outDir: resolve(__dirname, 'dist'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: resolve(__dirname, 'src/main.js'),
      output: {
        entryFileNames: '[name].[hash].js',
        chunkFileNames: '[name].[hash].js',
        assetFileNames: '[name].[hash][extname]',
      },
    },
  },

  server: {
    origin: 'http://localhost:5173',
    cors: true,
  },

  css: {
    devSourcemap: true,
  },
});
