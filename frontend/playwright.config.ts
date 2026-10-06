import { defineConfig, devices } from '@playwright/test';

const PORT = Number.parseInt(process.env.VITE_DEV_PORT ?? '5174', 10);

/**
 * E2E runs against the built SPA with the real backend expected on 8001.
 * CI starts both before invoking this config.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,

  /*
   * `php artisan serve` is single-process: it handles one request at a time.
   * Four browser workers queue behind each other, responses stretch past a
   * second, and assertions start failing on timing rather than behaviour.
   * Two workers keep the suite honest and still finish quickly.
   */
  workers: 2,

  // Same reason: allow for a queued request rather than racing the server.
  /*
   * Generous, because the stack under test is a single development server shared by two
   * browser projects: a sign-in costs a bcrypt verification and a dashboard first paint
   * waits on a chart chunk plus several analytics queries. A tight timeout here fails
   * tests for the harness's throughput rather than the product's behaviour.
   */
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? `http://127.0.0.1:${String(PORT)}`,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    /*
     * Pinned. The app picks its initial language from the browser, which is
     * correct behaviour — but it means an Arabic-locale machine renders the
     * Arabic UI and every English selector misses. The RTL experience is
     * covered deliberately by its own test rather than by accident of
     * whichever machine the suite runs on.
     */
    locale: 'en-US',
    timezoneId: 'Asia/Riyadh',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile-safari', use: { ...devices['iPhone 14'] } },
  ],
  /*
   * Tests run against the production build. The dev server's on-demand
   * transforms make parallel runs slow enough to time out, and the built
   * bundle is what ships. Set E2E_BASE_URL to point at an already-running
   * server instead.
   */
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : {
        command: 'npm run build && npm run preview',
        url: `http://127.0.0.1:${String(PORT)}`,
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
      },
});
