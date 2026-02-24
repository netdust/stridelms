import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  base: './',

  build: {
    outDir: 'dist',
    emptyDirOnBuild: true,
    manifest: true,
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'src/main.js'),
      },
    },
  },

  server: {
    origin: 'http://localhost:5173',
    cors: true,
    strictPort: true,
    port: 5173,
  },

  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
});
