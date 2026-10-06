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
  /*
   * One retry, everywhere — not only in CI.
   *
   * This stack is a development server on a developer's machine, and that machine may be
   * running something else: a suite that failed a test here was twice starved by another
   * project's test run on the same host, which showed up as a *refused connection* the
   * application reported correctly. A retry absorbs that.
   *
   * A retried test is reported as **flaky**, not as a pass, which is the point: the signal
   * stays in the output instead of being hidden by a longer timeout. A test that flakes for
   * a product reason still has to be fixed, and the report is where that is noticed.
   */
  retries: 1,

  /*
   * Two browser workers against a sixteen-worker development server (see scripts/e2e.sh).
   * More than two queue behind each other on a laptop and assertions start failing on
   * timing rather than behaviour; one serialises the suite for no benefit — measured at
   * nineteen minutes on a loaded machine against two minutes on an idle one, with *more*
   * failures, because the load was never this suite's concurrency.
   *
   * `E2E_WORKERS=1` is there for the case where it genuinely is: a host already busy with
   * something else.
   */
  workers: Number.parseInt(process.env.E2E_WORKERS ?? '2', 10),

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
