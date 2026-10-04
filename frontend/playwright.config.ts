import { defineConfig, devices } from '@playwright/test';

const PORT = 5174;

/**
 * E2E runs against the built SPA with the real backend expected on 8001.
 * CI starts both before invoking this config.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? `http://127.0.0.1:${String(PORT)}`,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile-safari', use: { ...devices['iPhone 14'] } },
  ],
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : {
        command: 'npm run dev',
        url: `http://127.0.0.1:${String(PORT)}`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
      },
});
