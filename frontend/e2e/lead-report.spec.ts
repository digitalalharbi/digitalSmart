import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * The lead section of a client report, as the client sees it: through a link, with no account, on a phone, in
 * either language. The aggregates come from the demo workspace's own leads (it seeds a lead-quality problem on
 * purpose), so nothing here is staged.
 */
test.describe('lead report', () => {
  for (const locale of ['en', 'ar'] as const) {
    test(`reads on a phone through a shared link, with no personal data (${locale})`, async ({
      page,
      browser,
      baseURL,
    }) => {
      await signIn(page, ACCOUNTS.owner);
      await page.goto('/reports');

      /* Made through the API, as the builder would: the builder's own flow is covered by its unit tests. */
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
          const clients = (await (
            await fetch('/api/clients?search=Alhambra', { credentials: 'include', headers })
          ).json()) as { data: { id: string }[] };
          const client = clients.data[0];

          if (client === undefined) {
            throw new Error('The demo client is missing.');
          }

          const report = (await (
            await fetch('/api/reports', {
              method: 'POST',
              credentials: 'include',
              headers,
              body: JSON.stringify({
                name,
                client_id: client.id,
                window: 'last_90_days',
                audience: 'client_safe',
                sections: [{ kind: 'lead' }],
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
        { name: `Lead report ${test.info().project.name} ${locale} ${String(test.info().retry)}` },
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
        await visiting.goto(`/shared-reports/${token}`);

        await expect(visiting.getByRole('heading', { level: 4 }).first()).toBeVisible({
          timeout: 30_000,
        });

        /* The funnel, the route mix and the response bars all drew. */
        expect(await visiting.getByRole('heading', { level: 4 }).count()).toBeGreaterThanOrEqual(3);

        /* Nothing wider than the screen. */
        const widths = await visiting.evaluate(() => ({
          scroll: document.documentElement.scrollWidth,
          client: document.documentElement.clientWidth,
        }));
        expect(widths.scroll).toBeLessThanOrEqual(widths.client);

        /* An aggregate has no contact point in it, and no way back into the agency's lead screens. */
        const text = await visiting.locator('body').innerText();
        expect(text).not.toMatch(/@/);
        expect(text).not.toMatch(/\+?\d{9,}/);
        await expect(visiting.locator('a[href*="/leads"]')).toHaveCount(0);
      } finally {
        await visitor.close();
      }
    });
  }
});
