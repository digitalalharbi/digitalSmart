import AxeBuilder from '@axe-core/playwright';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { expectNoSidewaysScroll, expectOneScreen } from './support/reach';

/**
 * The two screens a visitor meets before signing in — the public landing page and the sign-in screen — across the
 * browsers and devices they will actually use.
 *
 * Both are one screen on a desktop (no scrolling in either direction, nothing drawn off the screen) and neither may
 * scroll sideways on any phone. On a phone they may scroll down, and must stay usable: touch targets of at least
 * 44px, text fields of at least 16px (below that iOS Safari zooms the page on focus), and browser zoom left alone.
 *
 * Nothing here signs in or writes anything. A real sign-up does not exist, so what is asserted about the landing page's
 * call to action is that it goes to the real sign-in screen and nowhere else.
 *
 * This spec is the one the extra browser projects run (see `playwright.config.ts`). Screenshots are written only when
 * `ENTRY_SHOTS` names a directory, so the same spec is the evidence and the gate.
 */
type Size = { width: number; height: number };
type Locale = 'en' | 'ar';
type Theme = 'dark' | 'light';

const PAGES = [
  { path: '/', name: 'landing' },
  { path: '/login', name: 'login' },
] as const;

/* Arabic is the primary language, so it gets the whole matrix; English gets the sizes that bound it. */
const DESKTOP: Array<{ size: Size; locales: Locale[] }> = [
  { size: { width: 1920, height: 1080 }, locales: ['ar'] },
  { size: { width: 1600, height: 900 }, locales: ['ar'] },
  { size: { width: 1440, height: 900 }, locales: ['ar', 'en'] },
  { size: { width: 1366, height: 768 }, locales: ['ar', 'en'] },
  { size: { width: 1280, height: 720 }, locales: ['ar'] },
  { size: { width: 1024, height: 768 }, locales: ['ar', 'en'] },
];

const PHONES: Array<{ size: Size; locales: Locale[] }> = [
  { size: { width: 375, height: 667 }, locales: ['ar', 'en'] }, // small iPhone
  { size: { width: 375, height: 812 }, locales: ['ar'] },
  { size: { width: 390, height: 844 }, locales: ['ar', 'en'] }, // standard iPhone
  { size: { width: 393, height: 852 }, locales: ['ar'] },
  { size: { width: 412, height: 915 }, locales: ['ar'] }, // Android phone
  { size: { width: 430, height: 932 }, locales: ['ar'] }, // large iPhone
];

const TINY: Size = { width: 320, height: 568 };
const LANDSCAPE: Size = { width: 844, height: 390 };

async function open(
  page: Page,
  path: string,
  locale: Locale,
  theme: Theme = 'dark',
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
  await page.evaluate(() => document.fonts.ready);
}

async function shot(page: Page, project: string, name: string): Promise<void> {
  const directory = process.env['ENTRY_SHOTS'];

  if (directory === undefined || directory === '') {
    return;
  }

  mkdirSync(join(directory, project), { recursive: true });
  await page.screenshot({ path: join(directory, project, `${name}.png`) });
}

/**
 * What a one-screen page must not do: lose the things that matter off an edge, put two cards on each other, or cut a
 * heading, button or label short.
 */
async function expectIntact(page: Page, fullyInside: boolean): Promise<void> {
  const problems = await page.evaluate((inside) => {
    const found: string[] = [];
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const visible = (element: Element): boolean => {
      const box = element.getBoundingClientRect();

      return box.width > 0 && box.height > 0 && getComputedStyle(element).display !== 'none';
    };
    const label = (element: Element): string =>
      `${element.tagName.toLowerCase()}${element.id === '' ? '' : `#${element.id}`} "${(element.textContent ?? '').trim().slice(0, 24)}"`;

    /* The things a visitor came for are inside the screen: the logo, the headline, the way in. */
    const vital = [
      'img[data-logo]',
      'h1',
      'form input',
      'form button[type="submit"]',
      'a[href="/login"]',
    ];

    for (const selector of vital) {
      for (const element of document.querySelectorAll(selector)) {
        if (!visible(element)) {
          continue;
        }

        const box = element.getBoundingClientRect();

        if (
          box.left < -0.5 ||
          box.right > vw + 0.5 ||
          (inside && (box.top < -0.5 || box.bottom > vh + 0.5))
        ) {
          found.push(`${selector} is outside the screen: ${label(element)}`);
        }
      }
    }

    /* Headings, buttons and labels are not cut short by a clipped box. */
    for (const element of document.querySelectorAll('h1, h2, h3, button, a, label')) {
      if (!visible(element) || element.className.toString().includes('truncate')) {
        continue;
      }

      const style = getComputedStyle(element);

      if (style.overflowX !== 'visible' && element.scrollWidth > element.clientWidth + 1) {
        found.push(`text is cut off: ${label(element)}`);
      }
    }

    /* Cards do not overlap one another. */
    const cards = [...document.querySelectorAll('article, aside ul > li')].filter(visible);

    for (let first = 0; first < cards.length; first += 1) {
      for (let second = first + 1; second < cards.length; second += 1) {
        const a = cards[first]!.getBoundingClientRect();
        const b = cards[second]!.getBoundingClientRect();
        const across = Math.min(a.right, b.right) - Math.max(a.left, b.left);
        const down = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);

        if (across > 2 && down > 2) {
          found.push(`cards overlap: ${label(cards[first]!)} and ${label(cards[second]!)}`);
        }
      }
    }

    return found;
  }, fullyInside);

  expect(problems).toEqual([]);
}

/** Every control a thumb has to hit is large enough, and no text field is small enough to make iOS zoom. */
async function expectTouchFriendly(page: Page): Promise<void> {
  const problems = await page.evaluate(() => {
    const found: string[] = [];
    const interactive = document.querySelectorAll(
      'button, a[href], input:not([type="hidden"]), select, textarea',
    );

    for (const element of interactive) {
      const box = (element.closest('label') ?? element).getBoundingClientRect();

      if (box.width === 0 || box.height === 0 || getComputedStyle(element).display === 'none') {
        continue;
      }

      const name = `${element.tagName.toLowerCase()} "${(element.getAttribute('aria-label') ?? element.textContent ?? '').trim().slice(0, 24)}"`;

      if (Math.min(box.width, box.height) < 43.5) {
        found.push(
          `${name} is ${String(Math.round(box.width))}×${String(Math.round(box.height))}, under 44`,
        );
      }

      if (
        element instanceof HTMLInputElement &&
        element.type !== 'checkbox' &&
        Number.parseFloat(getComputedStyle(element).fontSize) < 16
      ) {
        found.push(`${name} text is under 16px, which makes iOS Safari zoom on focus`);
      }
    }

    return found;
  });

  expect(problems).toEqual([]);
}

for (const screen of PAGES) {
  test.describe(`${screen.name} on a desktop`, () => {
    test.skip(({ isMobile }) => isMobile, 'Desktop sizes are measured in the desktop projects.');

    for (const { size, locales } of DESKTOP) {
      for (const locale of locales) {
        test(`is one screen at ${String(size.width)}×${String(size.height)} (${locale})`, async ({
          page,
        }, info) => {
          await page.setViewportSize(size);
          await open(page, screen.path, locale);

          await expectOneScreen(page);
          await expectIntact(page, true);
          await expect(page.locator('html')).toHaveAttribute(
            'dir',
            locale === 'ar' ? 'rtl' : 'ltr',
          );

          await shot(
            page,
            info.project.name,
            `${screen.name}-${locale}-${String(size.width)}x${String(size.height)}`,
          );
        });
      }
    }

    for (const size of [DESKTOP[2]!.size, DESKTOP[5]!.size]) {
      for (const locale of ['ar', 'en'] as const) {
        test(`is one screen in the light theme at ${String(size.width)}×${String(size.height)} (${locale})`, async ({
          page,
        }) => {
          await page.setViewportSize(size);
          await open(page, screen.path, locale, 'light');

          await expectOneScreen(page);
          await expectIntact(page, true);
        });
      }
    }
  });

  test.describe(`${screen.name} on a phone`, () => {
    for (const { size, locales } of PHONES) {
      for (const locale of locales) {
        test(`fits ${String(size.width)}×${String(size.height)} (${locale})`, async ({
          page,
        }, info) => {
          await page.setViewportSize(size);
          await open(page, screen.path, locale);

          await expectNoSidewaysScroll(page);
          await expectIntact(page, false);

          if (info.project.use.hasTouch === true) {
            await expectTouchFriendly(page);
          }

          await shot(
            page,
            info.project.name,
            `${screen.name}-${locale}-${String(size.width)}x${String(size.height)}`,
          );
        });
      }
    }

    for (const locale of ['ar', 'en'] as const) {
      /*
       * 320×568 is a small, old phone. It may scroll down — that is acceptable — but it has to stay usable, in order of
       * importance: the logo, the headline, the form or the way in. Text keeps a readable size rather than shrinking to
       * remove the scrolling.
       */
      test(`stays usable at 320×568 (${locale})`, async ({ page }, info) => {
        await page.setViewportSize(TINY);
        await open(page, screen.path, locale);

        await expectNoSidewaysScroll(page);
        await expectIntact(page, false);

        const headline = await page
          .locator('h1')
          .first()
          .evaluate((element) => Number.parseFloat(getComputedStyle(element).fontSize));

        expect(headline, 'the headline stays readable').toBeGreaterThanOrEqual(20);

        const way =
          screen.name === 'login'
            ? page.locator('form input').first()
            : page.getByRole('link', { name: /Sign in|تسجيل الدخول/ }).first();

        await way.scrollIntoViewIfNeeded();
        await expect(way).toBeInViewport();

        if (info.project.use.hasTouch === true) {
          await expectTouchFriendly(page);
        }

        await shot(page, info.project.name, `${screen.name}-${locale}-320x568`);
      });

      test(`works in landscape at 844×390 (${locale})`, async ({ page }, info) => {
        await page.setViewportSize(LANDSCAPE);
        await open(page, screen.path, locale);

        await expectNoSidewaysScroll(page);
        await expectIntact(page, false);

        const way =
          screen.name === 'login'
            ? page.locator('form button[type="submit"]')
            : page.getByRole('link', { name: /Sign in|تسجيل الدخول/ }).first();

        await way.scrollIntoViewIfNeeded();
        await expect(way).toBeInViewport();
        await expect(way).toBeEnabled();

        await shot(page, info.project.name, `${screen.name}-${locale}-844x390`);
      });
    }
  });
}

test.describe('on a phone', () => {
  test('the sign-in form comes before the marketing', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await open(page, '/login', 'ar');

    const form = await page.locator('form').boundingBox();
    const brand = await page.locator('aside').boundingBox();

    expect(form).not.toBeNull();
    expect(brand).not.toBeNull();
    expect((form?.y ?? 0) < (brand?.y ?? 0)).toBe(true);
  });

  test('the landing navigation does not overflow, and the way in stays one tap away', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await open(page, '/', 'en');

    await expectNoSidewaysScroll(page);
    await expect(
      page.locator('header').getByRole('link', { name: 'Sign in', exact: true }),
    ).toBeInViewport();
  });
});

test.describe('the page respects the reader', () => {
  test('never disables browser zoom', async ({ page }) => {
    await open(page, '/login', 'en');

    const content = (await page.locator('meta[name="viewport"]').getAttribute('content')) ?? '';

    expect(content).not.toMatch(/user-scalable\s*=\s*(no|0)/i);
    expect(content).not.toMatch(/maximum-scale\s*=\s*1(\.0)?\b/i);
  });
});

test.describe('type, logo and assets', () => {
  for (const locale of ['ar', 'en'] as const) {
    for (const theme of ['dark', 'light'] as const) {
      test(`the brand faces load, digits stay Latin and the logo is whole (${locale}, ${theme})`, async ({
        page,
      }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await open(page, '/', locale, theme);

        const found = await page.evaluate(() => {
          const loaded = [
            ...new Set(
              [...document.fonts]
                .filter((face) => face.status === 'loaded')
                /* Firefox reports a family with its quotes; Chromium and WebKit without. */
                .map((face) => face.family.replaceAll('"', '')),
            ),
          ];
          const logo = [...document.querySelectorAll<HTMLImageElement>('img[data-logo]')].find(
            (image) => image.getBoundingClientRect().width > 0,
          );
          const box = logo?.getBoundingClientRect();
          const text = document.body.innerText;

          return {
            loaded,
            digits: /1,556/.test(text) && !/[٠-٩]/.test(text),
            logo:
              logo === undefined || box === undefined
                ? null
                : {
                    natural: logo.naturalWidth / logo.naturalHeight,
                    drawn: box.width / box.height,
                    transform: getComputedStyle(logo).transform,
                    complete: logo.complete && logo.naturalWidth > 0,
                  },
          };
        });

        expect(found.loaded).toContain('Sora');
        expect(found.digits, 'figures are written in Latin digits').toBe(true);
        expect(found.logo, 'the logo is on the page').not.toBeNull();
        expect(found.logo?.complete).toBe(true);
        /* Drawn in its own proportions — not squashed, not clipped — and never mirrored for right-to-left. */
        expect(Math.abs((found.logo?.drawn ?? 0) / (found.logo?.natural ?? 1) - 1)).toBeLessThan(
          0.03,
        );
        expect(found.logo?.transform).toBe('none');

        if (locale === 'ar') {
          expect(found.loaded).toContain('IBM Plex Sans Arabic');
        }
      });
    }
  }

  test('loads without a missing asset, a repeated font download, or a missing icon', async ({
    page,
  }) => {
    const failed: string[] = [];
    const fonts = new Map<string, number>();

    page.on('response', (response) => {
      const url = response.url();

      if (!url.includes('/api/') && response.status() >= 400) {
        failed.push(`${String(response.status())} ${url}`);
      }

      if (/\.(woff2?|ttf|otf)(\?|$)/.test(url)) {
        fonts.set(url, (fonts.get(url) ?? 0) + 1);
      }
    });

    await open(page, '/login', 'ar');
    await page.evaluate(() => document.fonts.ready);

    expect(failed).toEqual([]);
    expect([...fonts.entries()].filter(([, count]) => count > 1)).toEqual([]);

    for (const asset of [
      '/favicon.ico',
      '/favicon.svg',
      '/apple-touch-icon.png',
      '/site.webmanifest',
    ]) {
      const response = await page.request.get(asset);

      expect(response.status(), asset).toBe(200);
    }
  });
});

test.describe('the home address', () => {
  test('is the marketing page for somebody who is not signed in, and /welcome is the same page', async ({
    page,
  }) => {
    await open(page, '/', 'en');
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole('link', { name: 'Sign in', exact: true }).first()).toBeVisible();

    await open(page, '/welcome', 'en');
    await expect(page.getByRole('link', { name: 'Sign in', exact: true }).first()).toBeVisible();
  });
});

test.describe('the landing page call to action', () => {
  test('goes to the real sign-in screen, and there is no sign-up to go to', async ({ page }) => {
    await open(page, '/', 'en');

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
  /* axe is the same engine whichever browser drives it, so one browser measures it. */
  test.skip(
    ({ browserName, isMobile }) => browserName !== 'chromium' || isMobile,
    'axe runs once, in Chromium.',
  );

  for (const theme of ['dark', 'light'] as const) {
    for (const locale of ['en', 'ar'] as const) {
      for (const screen of PAGES) {
        test(`${screen.path} has no machine-detectable violation (${theme}, ${locale})`, async ({
          page,
        }) => {
          await open(page, screen.path, locale, theme);

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
