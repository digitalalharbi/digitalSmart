import { expect, test, type Page } from '@playwright/test';

/**
 * Typography is a product requirement here, not a styling detail: Arabic is a
 * first-class language, and the two scripts do not share metrics.
 *
 * These assertions exist because the previous build *named* both families
 * without ever loading them, so Arabic silently fell back to a system face and
 * read cramped. A named font that never loads is invisible in code review.
 */

async function typography(page: Page) {
  return page.evaluate(() => {
    const read = (selector: string) => {
      const element = document.querySelector(selector);
      if (!element) return null;
      const style = getComputedStyle(element);
      return {
        family: style.fontFamily.split(',')[0]!.replace(/"/g, ''),
        size: Number.parseFloat(style.fontSize),
        leading: Number.parseFloat(style.lineHeight),
        tracking: style.letterSpacing,
      };
    };

    return {
      dir: document.documentElement.dir,
      body: read('body'),
      h1: read('h1'),
      loaded: [
        ...new Set([...document.fonts].filter((f) => f.status === 'loaded').map((f) => f.family)),
      ],
    };
  });
}

test.describe('Latin typography', () => {
  test('loads Inter and tightens display text', async ({ page }) => {
    await page.goto('/login');
    await page.waitForFunction(() => document.fonts.status === 'loaded');

    const type = await typography(page);

    expect(type.loaded).toContain('Inter Variable');
    expect(type.body?.family).toBe('Inter Variable');
    // Latin display text takes optical tightening well.
    expect(type.h1?.tracking).not.toBe('normal');
  });
});

test.describe('Arabic typography', () => {
  test.use({ locale: 'ar-SA' });

  test('loads a real Arabic UI face rather than falling back', async ({ page }) => {
    await page.goto('/login');
    await page.waitForFunction(() => document.fonts.status === 'loaded');

    const type = await typography(page);

    expect(type.dir).toBe('rtl');
    expect(type.loaded).toContain('IBM Plex Sans Arabic');
    expect(type.body?.family).toBe('IBM Plex Sans Arabic');
  });

  test('never tracks Arabic, which would break the cursive joins', async ({ page }) => {
    await page.goto('/login');
    await page.waitForFunction(() => document.fonts.status === 'loaded');

    const type = await typography(page);

    // Positive tracking severs the joins; negative collides the glyphs.
    expect(type.body?.tracking).toBe('normal');
    expect(type.h1?.tracking).toBe('normal');
  });

  test('gives Arabic more room than Latin at the same role', async ({ page }) => {
    await page.goto('/login');
    await page.waitForFunction(() => document.fonts.status === 'loaded');
    const arabic = await typography(page);

    const latin = await page.evaluate(() => {
      document.documentElement.dir = 'ltr';
      const body = getComputedStyle(document.body);
      const h1 = document.querySelector('h1');
      return {
        size: Number.parseFloat(body.fontSize),
        ratio: Number.parseFloat(body.lineHeight) / Number.parseFloat(body.fontSize),
        h1Ratio: h1
          ? Number.parseFloat(getComputedStyle(h1).lineHeight) /
            Number.parseFloat(getComputedStyle(h1).fontSize)
          : 0,
      };
    });

    // Arabic reads optically smaller at a given px, so the scale steps up...
    expect(arabic.body!.size).toBeGreaterThan(latin.size);
    // ...and the diacritic band needs looser leading than Latin.
    const arabicRatio = arabic.body!.leading / arabic.body!.size;
    expect(arabicRatio).toBeGreaterThan(latin.ratio);

    const arabicH1Ratio = arabic.h1!.leading / arabic.h1!.size;
    expect(arabicH1Ratio).toBeGreaterThan(latin.h1Ratio);
  });

  test('keeps Western digits so figures reconcile with platform dashboards', async ({ page }) => {
    await page.goto('/login');

    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(/[٠-٩]/);
  });
});
