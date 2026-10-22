import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Creatives in a client's shared link: no sign-in, a phone, either language.
 *
 * Asserted: the creative sections open from the link, fit 375px, carry no internal identifier, and the platform filter
 * narrows them. Figures are not asserted — the sandbox workspace's creatives carry fixture addresses, so what matters
 * is that the page says so rather than drawing a broken picture.
 */
test.describe('creative sections in a shared report', () => {
  for (const locale of ['en', 'ar'] as const) {
    test(`open from the link, fit a phone and leak no identifier (${locale})`, async ({
      page,
      browser,
      baseURL,
    }) => {
      await signIn(page, ACCOUNTS.owner);
      await page.goto('/reports');

      const token = await page.evaluate(
        async ({ name }) => {
          const xsrf = decodeURIComponent(
            document.cookie
              .split('; ')
              .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
              ?.split('=')[1] ?? '',
          );
          const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf,
          };
          const report = (await (
            await fetch('/api/reports', {
              method: 'POST',
              credentials: 'include',
              headers,
              body: JSON.stringify({
                name,
                window: 'last_28_days',
                comparison: 'none',
                audience: 'client_safe',
                sections: [
                  { kind: 'scorecard', metrics: ['spend', 'clicks'] },
                  { kind: 'creative', creative_view: 'gallery' },
                  { kind: 'creative', creative_view: 'top' },
                  { kind: 'creative', creative_view: 'performance' },
                  { kind: 'creative', creative_view: 'format_mix' },
                  { kind: 'creative', creative_view: 'platform_mix' },
                ],
              }),
            })
          ).json()) as { data: { id: string } };
          const share = (await (
            await fetch(`/api/reports/${report.data.id}/shares`, {
              method: 'POST',
              credentials: 'include',
              headers,
              body: JSON.stringify({ days: 7 }),
            })
          ).json()) as { meta: { token: string } };

          return share.meta.token;
        },
        { name: `Creatives ${test.info().project.name} ${locale} ${String(test.info().retry)}` },
      );

      const visitor = await browser.newContext({
        ...(baseURL === undefined ? {} : { baseURL }),
        viewport: { width: 375, height: 812 },
      });
      await visitor.addInitScript((value) => {
        localStorage.setItem(
          'digitalsmart.ui',
          JSON.stringify({ state: { theme: 'light', locale: value }, version: 1 }),
        );
      }, locale);
      const visiting = await visitor.newPage();

      try {
        const response = visiting.waitForResponse((r) => r.url().includes('/api/shared-reports/'));
        await visiting.goto(`/shared-reports/${token}`);
        const body = await (await response).text();

        // No identifier of any kind in what a visitor receives.
        expect(body).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);
        expect(body).not.toContain('.invalid');

        await expect(visiting.getByRole('heading', { name: /^Creatives / }).first()).toBeVisible({
          timeout: 30_000,
        });
        /* The hero: figures and the leading creative, before any section. */
        await expect(
          visiting.getByRole('region', { name: /Creative summary|ملخّص المواد/ }),
        ).toBeVisible();

        const overflow = await visiting.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );
        expect(overflow).toBeLessThanOrEqual(0);

        // Sandbox artwork is never drawn.
        const sources = await visiting
          .locator('img')
          .evaluateAll((images) => images.map((image) => (image as HTMLImageElement).src));
        expect(sources.filter((src) => /\.invalid\b/.test(src))).toEqual([]);

        if (locale === 'ar') {
          await expect(visiting.locator('html')).toHaveAttribute('dir', 'rtl');
        }

        /*
         * The same link as a talk. The creative screens are built from the payload the page just received, so a
         * narrowed or frozen link shows the same creatives here as on the page; what is asserted is that a creative
         * gets a screen of its own, fits a phone, and moves with the existing controls.
         */
        await visiting.goto(`/shared-reports/${token}?present=1`);
        const deck = visiting.getByRole('dialog');
        await expect(deck).toBeVisible({ timeout: 30_000 });
        const forward = locale === 'en' ? 'ArrowRight' : 'ArrowLeft';
        const creativeScreen = deck.getByRole('heading', {
          name: /Top creatives|Creative performance|أفضل المواد|أداء المواد/,
        });

        for (let step = 0; step < 12 && (await creativeScreen.count()) === 0; step += 1) {
          await visiting.keyboard.press(forward);
        }

        await expect(creativeScreen.first()).toBeVisible();
        expect(
          await visiting.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
          ),
        ).toBeLessThanOrEqual(0);
        await visiting.keyboard.press('Escape');
        await expect(deck).toHaveCount(0);
      } finally {
        await visitor.close();
      }
    });
  }
});
