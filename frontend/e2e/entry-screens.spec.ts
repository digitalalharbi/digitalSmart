import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * The two screens a visitor meets before signing in: the public landing page and the sign-in screen.
 *
 * Both are one screen on a desktop — no scrolling in either direction — and neither may overflow sideways on a phone.
 * Nothing here signs in or writes anything. A real sign-up does not exist, so what is asserted about the landing page's
 * call to action is that it goes to the real sign-in screen and nowhere else.
 *
 * Screenshots are written only when `ENTRY_SHOTS` names a directory, so the same spec is the evidence and the gate.
 */
const DESKTOP = [
  { width: 1440, height: 900 },
  { width: 1366, height: 768 },
  { width: 1280, height: 720 },
  { width: 1024, height: 768 },
] as const;

const PHONES = [
  { width: 390, height: 844 },
  { width: 375, height: 812 },
] as const;

async function open(
  page: Page,
  path: string,
  locale: 'en' | 'ar',
  theme: 'dark' | 'light' = 'dark',
): Promise<void> {
  await page.addInitScript(
    ([value, mode]) => {
      localStorage.setItem(
        'digitalsmart.ui',
        JSON.stringify({ state: { theme: mode, locale: value }, version: 1 }),
      );
    },
    [locale, theme] as const,
  );
  await page.goto(path);
  await expect(page.locator('h1').first()).toBeVisible({ timeout: 30_000 });
}

/** How far the page reaches past the screen, in pixels, in each direction. Zero is one screen. */
async function overflow(page: Page): Promise<{ sideways: number; down: number; clipped: number }> {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const root = document.querySelector('#root > div') ?? document.body;
    let clipped = 0;

    for (const element of root.querySelectorAll('*')) {
      const box = element.getBoundingClientRect();

      if (box.width === 0 || box.height === 0 || getComputedStyle(element).display === 'none') {
        continue;
      }

      clipped = Math.max(clipped, box.bottom - window.innerHeight, box.right - window.innerWidth);
    }

    return {
      sideways: doc.scrollWidth - doc.clientWidth,
      down: doc.scrollHeight - window.innerHeight,
      clipped: Math.round(clipped),
    };
  });
}

async function shot(page: Page, name: string): Promise<void> {
  const directory = process.env['ENTRY_SHOTS'];

  if (directory === undefined || directory === '') {
    return;
  }

  mkdirSync(directory, { recursive: true });
  await page.screenshot({ path: join(directory, `${name}.png`) });
}

for (const locale of ['ar', 'en'] as const) {
  for (const screen of [
    { path: '/welcome', name: 'landing' },
    { path: '/login', name: 'login' },
  ] as const) {
    test.describe(`${screen.name} on a desktop (${locale})`, () => {
      test.skip(({ isMobile }) => isMobile, 'Desktop sizes are measured in the desktop project.');

      for (const size of DESKTOP) {
        test(`is one screen at ${String(size.width)}×${String(size.height)}`, async ({ page }) => {
          await page.setViewportSize(size);
          await open(page, screen.path, locale);

          const reach = await overflow(page);

          expect(reach.sideways, 'sideways scroll').toBeLessThanOrEqual(0);
          expect(reach.down, 'vertical scroll').toBeLessThanOrEqual(1);
          expect(
            reach.clipped,
            'something is drawn below or beside the screen',
          ).toBeLessThanOrEqual(1);
          await expect(page.locator('html')).toHaveAttribute(
            'dir',
            locale === 'ar' ? 'rtl' : 'ltr',
          );

          await shot(page, `${screen.name}-${locale}-${String(size.width)}x${String(size.height)}`);
        });
      }
    });
  }

  test.describe(`on a phone (${locale})`, () => {
    for (const size of PHONES) {
      for (const screen of ['/welcome', '/login'] as const) {
        test(`${screen} does not scroll sideways at ${String(size.width)}×${String(size.height)}`, async ({
          page,
        }) => {
          await page.setViewportSize(size);
          await open(page, screen, locale);

          expect((await overflow(page)).sideways).toBeLessThanOrEqual(0);

          await shot(
            page,
            `${screen === '/welcome' ? 'landing' : 'login'}-${locale}-${String(size.width)}x${String(size.height)}`,
          );
        });
      }
    }

    test('the sign-in form comes before the marketing', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 812 });
      await open(page, '/login', locale);

      const form = await page.locator('form').boundingBox();
      const brand = await page.locator('aside').boundingBox();

      expect(form).not.toBeNull();
      expect(brand).not.toBeNull();
      expect((form?.y ?? 0) < (brand?.y ?? 0)).toBe(true);
    });
  });
}

test.describe('the landing page call to action', () => {
  test('goes to the real sign-in screen, and there is no sign-up to go to', async ({ page }) => {
    await open(page, '/welcome', 'en');

    const links = page.getByRole('link', { name: 'Sign in', exact: true });

    expect(await links.count()).toBeGreaterThan(0);

    for (const link of await links.all()) {
      await expect(link).toHaveAttribute('href', '/login');
    }

    await expect(
      page.getByRole('link', { name: /sign up|register|create account|trial/i }),
    ).toHaveCount(0);

    await links.first().click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(
      page.getByRole('heading', { level: 1, name: 'Sign in to digitalSmart' }),
    ).toBeVisible();
  });
});

const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

test.describe('accessibility of the two entry screens', () => {
  for (const theme of ['dark', 'light'] as const) {
    for (const locale of ['en', 'ar'] as const) {
      for (const path of ['/welcome', '/login'] as const) {
        test(`${path} has no machine-detectable violation (${theme}, ${locale})`, async ({
          page,
        }) => {
          await open(page, path, locale, theme);

          const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();

          expect(
            violations.map(
              (violation) =>
                `${violation.id} (${violation.impact ?? 'n/a'}): ${violation.nodes
                  .slice(0, 3)
                  .map((node) => node.target.join(' '))
                  .join(' | ')}`,
            ),
          ).toEqual([]);
        });
      }
    }
  }
});
