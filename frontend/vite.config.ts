import { Agent } from 'node:http';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// digitalSmart runs on 5174/8001 so it does not collide with the common 5173/8000
// defaults. Both are overridable: running several stacks on one machine is normal,
// and a port clash should be a config change, not a code change.
const API_TARGET = process.env.VITE_DEV_API_PROXY ?? 'http://127.0.0.1:8001';
const PORT = Number.parseInt(process.env.VITE_DEV_PORT ?? '5174', 10);

/*
 * The API proxy opens a fresh connection per request instead of pooling them.
 *
 * Node has kept connections alive by default since 19, and PHP's built-in development
 * server — which is what sits behind this proxy locally and in the end-to-end suite —
 * binds a kept-alive connection to one forked worker and closes it on its own schedule.
 * When Node then picks that socket for the next request the read fails, the proxy
 * returns nothing, and the application correctly reports that the server could not be
 * reached. It looks exactly like a bug in the sign-in screen, and it was mistaken for
 * one: it appeared only under WebKit, which opens and reuses more connections than
 * Chromium does.
 *
 * A new connection per request costs a local TCP handshake, which is not measurable
 * next to a request that has to be retried.
 */
const PROXY = {
  changeOrigin: true,
  agent: new Agent({ keepAlive: false }),
} as const;

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
      '/api': { target: API_TARGET, ...PROXY },
      '/sanctum': { target: API_TARGET, ...PROXY },
      '/broadcasting': { target: API_TARGET, ...PROXY },
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
      '/api': { target: API_TARGET, ...PROXY },
      '/sanctum': { target: API_TARGET, ...PROXY },
      '/broadcasting': { target: API_TARGET, ...PROXY },
    },
  },
  build: {
    sourcemap: true,
    chunkSizeWarningLimit: 900,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    /*
     * Fifteen seconds rather than the five-second default.
     *
     * Not because any test here is slow: every one of them asserts the result of a render or a click. The
     * suite builds a fresh jsdom per file — thirty-odd of them, in parallel — and on a loaded machine the
     * construction of that environment alone can eat most of a five-second budget before the test body
     * runs. The symptom is a test that passes alone and times out in the full run, which is the worst kind
     * of failure to have: it looks like a defect in whatever was touched last.
     *
     * This raises an infrastructure budget, not an assertion. Nothing here asserts how fast a render is,
     * so a longer ceiling cannot hide a regression; a genuinely hung test still fails, fifteen seconds
     * later. The alternative — running the files serially — would slow every run to protect against a
     * condition that only appears under load.
     */
    testTimeout: 15_000,
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
