import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// digitalSmart runs on 5174/8001 so it does not collide with the common 5173/8000
// defaults. Both are overridable: running several stacks on one machine is normal,
// and a port clash should be a config change, not a code change.
const API_TARGET = process.env.VITE_DEV_API_PROXY ?? 'http://127.0.0.1:8001';
const PORT = Number.parseInt(process.env.VITE_DEV_PORT ?? '5174', 10);

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // Pinned to IPv4: Vite would otherwise bind ::1 only, which breaks tools,
    // Playwright and docs that address 127.0.0.1. strictPort is deliberate —
    // silently moving to another port would make the API proxy and the
    // end-to-end suite address the wrong server.
    host: '127.0.0.1',
    port: PORT,
    strictPort: true,
    proxy: {
      '/api': { target: API_TARGET, changeOrigin: true },
      '/sanctum': { target: API_TARGET, changeOrigin: true },
      '/broadcasting': { target: API_TARGET, changeOrigin: true },
    },
  },
  // The preview server mirrors dev, so end-to-end tests can run against the
  // built artifact — what actually ships — rather than the dev transform
  // pipeline, which is slower and not representative.
  preview: {
    host: '127.0.0.1',
    port: PORT,
    strictPort: true,
    proxy: {
      '/api': { target: API_TARGET, changeOrigin: true },
      '/sanctum': { target: API_TARGET, changeOrigin: true },
      '/broadcasting': { target: API_TARGET, changeOrigin: true },
    },
  },
  build: {
    sourcemap: true,
    chunkSizeWarningLimit: 900,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: true,
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
    exclude: ['e2e/**', 'node_modules/**'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      include: ['src/**/*.{ts,tsx}'],
      exclude: [
        'src/**/*.{test,spec}.{ts,tsx}',
        'src/test/**',
        'src/main.tsx',
        'src/vite-env.d.ts',
      ],
    },
  },
});
