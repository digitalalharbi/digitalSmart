import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * The signed-in application on a phone.
 *
 * Smoke, not redesign: every main surface fits the screen, the bottom bar and the drawer work, the header controls can
 * be reached with a thumb and a dialog fits the viewport. Run in both languages, in the default dark theme, on the
 * phone browsers (Mobile Safari, Mobile Chromium) and on Chromium narrowed to a phone.
 */
const SURFACES = [
  '/',
  '/campaigns',
  '/ad-groups',
  '/ads',
  '/analytics',
  '/creatives',
  '/reports',
  '/notifications',
];

/* Eight surfaces behind a sign-in, on a development server: more than the default 45 seconds, by design. */
test.setTimeout(150_000);

const MORE: Record<'en' | 'ar', string> = { en: 'More', ar: 'المزيد' };

async function arrive(page: Page, locale: 'en' | 'ar'): Promise<void> {
  await page.setViewportSize({ width: 375, height: 812 });
  await signIn(page, ACCOUNTS.owner);
  await page.evaluate((value) => {
    localStorage.setItem(
      'digitalsmart.ui',
      JSON.stringify({ state: { theme: 'dark', locale: value }, version: 1 }),
    );
  }, locale);
}

for (const locale of ['ar', 'en'] as const) {
  test.describe(`signed in, on a phone (${locale})`, () => {
    test('every main surface fits the screen', async ({ page }, info) => {
      await arrive(page, locale);

      const offenders: string[] = [];

      for (const surface of SURFACES) {
        await page.goto(surface);

        const heading = await page
          .getByRole('heading', { level: 1 })
          .first()
          .waitFor({ state: 'visible', timeout: 30_000 })
          .then(() => true)
          .catch(() => false);

        if (!heading) {
          offenders.push(`${surface} shows no heading`);
          continue;
        }
        /* Let the page settle: a surface that overflows does so once its figures arrive. */
        await page.waitForTimeout(600);

        const sideways = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );

        if (sideways > 1) {
          offenders.push(`${surface} is ${String(sideways)}px wider than the screen`);
        }
      }

      expect(offenders).toEqual([]);

      await page.goto('/');
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({
        timeout: 30_000,
      });

      if (process.env['ENTRY_SHOTS'] !== undefined && process.env['ENTRY_SHOTS'] !== '') {
        await page.screenshot({
          path: `${process.env['ENTRY_SHOTS']}/${info.project.name}/shell-${locale}-375x812.png`,
        });
      }
    });

    test('the bottom bar, the drawer and the header can be used with a thumb', async ({ page }) => {
      await arrive(page, locale);
      await page.goto('/');
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({
        timeout: 30_000,
      });

      const more = page.getByRole('button', { name: MORE[locale], exact: true });
      const bar = page.locator('nav').filter({ has: more });

      await expect(bar).toBeVisible();

      /* The bar sits inside the screen and every target in it is a thumb's size. */
      const box = await bar.boundingBox();
      expect(box).not.toBeNull();
      expect((box?.y ?? 0) + (box?.height ?? 0)).toBeLessThanOrEqual(812 + 1);

      for (const target of await bar.locator('a, button').all()) {
        const size = await target.boundingBox();
        expect(Math.min(size?.width ?? 0, size?.height ?? 0)).toBeGreaterThanOrEqual(43.5);
      }

      /* A tap on an area goes there. */
      const links = bar.getByRole('link');
      const second = links.nth(1);
      const target = await second.getAttribute('href');

      await second.click();
      await expect(page).toHaveURL(new RegExp(`${(target ?? '/').replace('/', '\\/')}$`));

      /* "More" opens the drawer over the page, and Escape closes it. */
      await more.click();
      const drawer = page.locator('div.fixed.inset-0.z-40');

      await expect(drawer).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(drawer).toBeHidden();

      /* The header's controls are on the screen, not past its edge. */
      for (const button of await page.locator('header button').all()) {
        if (!(await button.isVisible())) {
          continue;
        }

        const size = await button.boundingBox();
        expect((size?.x ?? 0) + (size?.width ?? 0)).toBeLessThanOrEqual(375 + 1);
        expect(size?.x ?? 0).toBeGreaterThanOrEqual(-1);
      }

      await expectNoSidewaysScroll(page);
    });

    test('a dialog fits the screen', async ({ page }) => {
      await arrive(page, locale);
      await page.goto('/');
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({
        timeout: 30_000,
      });

      await page.keyboard.press('Control+k');

      const dialog = page.getByRole('dialog').first();

      if (await dialog.isVisible().catch(() => false)) {
        const size = await dialog.boundingBox();

        expect(size?.x ?? 0).toBeGreaterThanOrEqual(-1);
        expect((size?.x ?? 0) + (size?.width ?? 0)).toBeLessThanOrEqual(375 + 1);
        expect((size?.y ?? 0) + (size?.height ?? 0)).toBeLessThanOrEqual(812 + 1);
      }

      await expectNoSidewaysScroll(page);
    });
  });
}
