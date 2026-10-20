import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Making a report as six short choices, on a phone, in either language.
 *
 * Nothing is saved here: the wizard writes only on its last step, and what is asserted is that every step fits the
 * screen and that the last choice has no default.
 */
test.describe('report wizard', () => {
  test.use({ viewport: { width: 375, height: 812 } });

  for (const locale of ['en', 'ar'] as const) {
    test(`fits a phone at every step and leaves who it is for unchosen (${locale})`, async ({
      page,
    }) => {
      await signIn(page, ACCOUNTS.owner);
      await page.evaluate((value) => {
        localStorage.setItem(
          'digitalsmart.ui',
          JSON.stringify({ state: { theme: 'light', locale: value }, version: 1 }),
        );
      }, locale);
      await page.goto('/reports');

      const next = locale === 'en' ? 'Next' : 'التالي';
      const create = locale === 'en' ? 'Create report' : 'إنشاء التقرير';
      const executive = locale === 'en' ? /Executive/ : /تنفيذي/;

      await page
        .getByRole('button', { name: locale === 'en' ? 'New report' : 'تقرير جديد', exact: true })
        .click();

      const overflow = (): Promise<number> =>
        page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );

      await expect(page.getByRole('button', { name: next, exact: true })).toBeVisible({
        timeout: 30_000,
      });
      expect(await overflow()).toBeLessThanOrEqual(0);

      await page.getByRole('button', { name: next, exact: true }).click();
      await page.getByRole('button', { name: executive }).first().click();

      for (let step = 0; step < 4; step += 1) {
        await page.getByRole('button', { name: next, exact: true }).click();
        expect(await overflow()).toBeLessThanOrEqual(0);
      }

      /* The last step: nothing is chosen, so nothing can be created. */
      await expect(page.getByRole('button', { name: create })).toBeDisabled();
    });
  }
});
