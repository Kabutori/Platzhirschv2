import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig(({ command }) => ({
  plugins: [react()],
  base: command === 'serve' ? '/' : '/admin/',
  build: { outDir: '../app/public/admin', emptyOutDir: true },
  server: {
    proxy: {
      '/api': `http://127.0.0.1:${process.env.PLATZHIRSCH_DEV_API_PORT || '8000'}`,
      '/widget.js': `http://127.0.0.1:${process.env.PLATZHIRSCH_DEV_API_PORT || '8000'}`,
    },
  },
}));
