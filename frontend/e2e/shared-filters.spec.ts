import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Narrowing a shared report, as a visitor with a link and no account.
 *
 * The invariant under test is one sentence: the token defines access and a filter only narrows it. So the happy path
 * is short, and most of what is here is somebody trying to make the report show more than it was sent to show.
 */

/** Make an agency-wide report over every connected platform and share it, as the agency would. */
async function shareReport(page: Page, name: string): Promise<string> {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/reports');

  return page.evaluate(
    async ({ title }) => {
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
            name: title,
            window: 'last_28_days',
            comparison: 'previous_period',
            audience: 'client_safe',
            sections: [
              { kind: 'scorecard', metrics: ['clicks', 'impressions'] },
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
    { title: name },
  );
}

test.describe('shared report filters', () => {
  test('narrows by platform, shows it as a chip, survives a reload, and clears in one action', async ({
    page,
    browser,
    baseURL,
  }) => {
    const token = await shareReport(
      page,
      `Filters ${test.info().project.name} ${String(test.info().retry)}`,
    );
    const visitor = await browser.newContext(baseURL === undefined ? {} : { baseURL });
    const visiting = await visitor.newPage();

    try {
      await visiting.goto(`/shared-reports/${token}`);
      await expect(visiting.getByRole('button', { name: /^Filters/ })).toBeVisible({
        timeout: 30_000,
      });

      await visiting.getByRole('button', { name: /^Filters/ }).click();
      const sheet = visiting.getByRole('dialog');
      await sheet.getByRole('button', { name: 'Meta Ads' }).click();
      await sheet.getByRole('button', { name: 'Done' }).click();

      await expect(visiting.getByRole('button', { name: 'Remove Meta Ads' })).toBeVisible();
      await expect(visiting).toHaveURL(/platform=meta/);

      /* A copied address opens the same narrowing — and still only what this token shows. */
      await visiting.reload();
      await expect(visiting.getByRole('button', { name: 'Remove Meta Ads' })).toBeVisible({
        timeout: 30_000,
      });

      await visiting.getByRole('button', { name: 'Clear filters' }).click();
      await expect(visiting.getByRole('button', { name: 'Remove Meta Ads' })).toHaveCount(0);
      await expect(visiting).not.toHaveURL(/platform=/);
    } finally {
      await visitor.close();
    }
  });

  test('refuses a selection that is not part of the report, and shows the whole report instead', async ({
    page,
    browser,
    baseURL,
  }) => {
    const token = await shareReport(
      page,
      `Widening ${test.info().project.name} ${String(test.info().retry)}`,
    );
    const visitor = await browser.newContext(baseURL === undefined ? {} : { baseURL });
    const visiting = await visitor.newPage();

    try {
      /* A campaign key nobody issued, a date from another century, and every id a visitor might try. */
      await visiting.goto(
        `/shared-reports/${token}?campaign=forgedkey1234567&from=2001-01-01&client_id=other&report=x`,
      );

      await expect(
        visiting.getByText(/not part of this report, so the whole report is shown/),
      ).toBeVisible({
        timeout: 30_000,
      });
      await expect(visiting).not.toHaveURL(/campaign=|from=/);
      await expect(visiting.getByRole('heading', { name: /^Widening/ })).toBeVisible();
    } finally {
      await visitor.close();
    }
  });

  for (const locale of ['en', 'ar'] as const) {
    test(`stays inside a phone screen with the filter sheet open (${locale})`, async ({
      page,
      browser,
      baseURL,
    }) => {
      const token = await shareReport(
        page,
        `Phone ${test.info().project.name} ${locale} ${String(test.info().retry)}`,
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
        await visiting.goto(`/shared-reports/${token}?platform=meta`);
        await expect(visiting.getByTestId('shared-filters')).toBeVisible({ timeout: 30_000 });

        const bar = visiting.getByTestId('shared-filters').getByRole('button').first();
        await bar.click();
        await expect(visiting.getByRole('dialog')).toBeVisible();

        const widths = await visiting.evaluate(() => ({
          scroll: document.documentElement.scrollWidth,
          client: document.documentElement.clientWidth,
        }));
        expect(widths.scroll).toBeLessThanOrEqual(widths.client);

        /* No internal identifier anywhere on the page or in its address. */
        const text = await visiting.locator('body').innerText();
        expect(text).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/);
        expect(visiting.url()).not.toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-/);
      } finally {
        await visitor.close();
      }
    });
  }
});
