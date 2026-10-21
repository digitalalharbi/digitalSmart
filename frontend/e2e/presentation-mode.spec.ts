import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * A shared report as a talk: full screen, one story at a time, on a phone, in either language.
 *
 * What is asserted is the deck's behaviour, not its figures: it opens from the link, fits the screen at every step, moves
 * with the arrows the language implies, and gives the page back on Escape.
 */
test.describe('presentation mode', () => {
  for (const locale of ['en', 'ar'] as const) {
    test(`opens from the link, fits a phone and moves with the language's own arrows (${locale})`, async ({
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
                comparison: 'previous_period',
                audience: 'client_safe',
                sections: [
                  { kind: 'scorecard', metrics: ['impressions', 'clicks', 'conversions'] },
                  { kind: 'breakdown', metrics: ['clicks'], dimension: 'provider' },
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
        { name: `Talk ${test.info().project.name} ${locale} ${String(test.info().retry)}` },
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
        await visiting.goto(`/shared-reports/${token}?present=1`);

        const deck = visiting.getByRole('dialog');
        await expect(deck).toBeVisible({ timeout: 30_000 });

        const overflow = (): Promise<number> =>
          visiting.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
          );
        expect(await overflow()).toBeLessThanOrEqual(0);

        /* The language's own forward arrow: right in English, left in Arabic. */
        const forward = locale === 'en' ? 'ArrowRight' : 'ArrowLeft';
        const counter = deck.locator('p[aria-hidden]').first();
        await expect(counter).toContainText('1 /');

        await visiting.keyboard.press(forward);
        await expect(counter).toContainText('2 /');
        expect(await overflow()).toBeLessThanOrEqual(0);

        await visiting.keyboard.press('End');
        expect(await overflow()).toBeLessThanOrEqual(0);

        await visiting.keyboard.press('Escape');
        await expect(deck).toHaveCount(0);
      } finally {
        await visitor.close();
      }
    });
  }
});
