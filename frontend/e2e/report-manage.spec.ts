import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * A report's own page, and the campaign report that reaches it.
 *
 * Read-only: nothing here saves a report. What is asserted is that the page opens from the library, that its tabs
 * are the ones a person needs before sharing, and that the campaign report preset is offered and does not overflow
 * a phone.
 */
test.describe('report management page', () => {
  test('opens from the library and offers settings, sharing and files', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/reports');

    await page
      .getByRole('button', { name: /Executive|Performance|performance/ })
      .first()
      .click();
    await page.getByRole('link', { name: 'Manage', exact: true }).click();

    await expect(page).toHaveURL(/\/reports\/[0-9a-f-]+\/manage$/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('tab', { name: 'Settings' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Sharing' })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Files and schedule' })).toBeVisible();

    await page.getByRole('tab', { name: 'Settings' }).click();
    await expect(
      page.getByText('Everything the client runs').or(page.getByText(/About \d+ campaign/)),
    ).toBeVisible();
  });

  test.describe('on a phone', () => {
    test.use({ viewport: { width: 375, height: 812 } });

    test('the campaign report is offered and nothing overflows', async ({ page }) => {
      await signIn(page, ACCOUNTS.owner);
      await page.goto('/reports');
      await page.getByRole('button', { name: 'New report', exact: true }).click();
      await page.getByRole('button', { name: 'Next', exact: true }).click();

      await expect(page.getByRole('button', { name: /Campaign report/ })).toBeVisible({
        timeout: 30_000,
      });

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(overflow).toBeLessThanOrEqual(0);
    });
  });
});
