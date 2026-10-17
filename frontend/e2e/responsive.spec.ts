import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * No page may be wider than the screen it is on.
 *
 * A single long unbreakable string in a one-column grid makes the card — and so the page — wider than a phone, and
 * on a right-to-left page the overflow lands on the side the reader starts from. It is invisible on a desktop and
 * obvious the moment somebody opens the product on the device an account manager actually carries, so every main
 * surface is measured here, in both directions, at the narrow viewport.
 */
const ROUTES = [
  '/',
  '/portfolio',
  '/projects',
  '/clients',
  '/campaigns',
  '/creative-hub',
  '/tasks',
  '/approvals',
  '/tracking',
  '/leads',
  '/budget-control',
  '/analytics',
  '/reports',
  '/presentations',
  '/integrations',
  '/administration',
  '/notifications',
];

test.describe('narrow screens', () => {
  test.use({ viewport: { width: 375, height: 812 } });

  for (const locale of ['en', 'ar'] as const) {
    test(`no surface is wider than the screen (${locale})`, async ({ page }) => {
      await signIn(page, ACCOUNTS.owner);
      await page.evaluate((value) => {
        localStorage.setItem(
          'digitalsmart.ui',
          JSON.stringify({ state: { theme: 'light', locale: value }, version: 1 }),
        );
      }, locale);

      const offenders: string[] = [];

      for (const route of ROUTES) {
        await page.goto(route);
        await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({
          timeout: 30_000,
        });
        await page.waitForLoadState('networkidle').catch(() => undefined);

        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );

        if (overflow > 1) {
          offenders.push(`${route} (+${String(overflow)}px)`);
        }
      }

      expect(offenders).toEqual([]);
    });
  }
});
