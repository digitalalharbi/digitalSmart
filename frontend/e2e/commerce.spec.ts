import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Open store revenue as a given account.
 *
 * Sign-in happens per test rather than in a `beforeEach`, because one test needs a different account:
 * signing in twice in one browser context leaves a session the second attempt cannot replace.
 */
async function openCommerce(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/commerce');
  await expect(page.getByRole('heading', { level: 1, name: 'Store revenue' })).toBeVisible({
    timeout: 30_000,
  });
}

test.describe('store revenue', () => {
  test('makes the reader choose which authority they are looking at', async ({ page }) => {
    await openCommerce(page);

    /*
     * The decision this page refuses to make quietly. A Salla total counts invoices; a GA4 total counts
     * what a browser reported before the payment failed. The selector is the first control on the page
     * and what it is set to is repeated in words on the total itself.
     */
    const sources = page.getByRole('group', { name: 'Revenue according to' });

    await expect(sources).toBeVisible();
    await expect(sources.getByRole('button', { name: 'Salla' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    await expect(page.getByText(/invoiced this money|measurement of sales/)).toBeVisible();
  });

  test('says plainly when a figure is modelled rather than recorded', async ({ page }) => {
    await openCommerce(page);

    await page.getByRole('button', { name: 'Attributed (modelled)' }).click();

    /*
     * Attributed revenue is a model's output. It must never be presented to a client as actual, and the
     * page says so at the moment somebody selects it rather than in a footnote.
     */
    await expect(page.getByText(/must not be presented to a client as actual/)).toBeVisible({
      timeout: 20_000,
    });
  });

  test('explains that sources are not combined', async ({ page }) => {
    await openCommerce(page);

    await expect(page.getByText(/There is no combined figure/)).toBeVisible();
  });

  test('does not offer store revenue to a creative', async ({ page }) => {
    await signIn(page, ACCOUNTS.creative);

    // The rail only exists at desktop width, so assert on it there.
    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(page.getByRole('link', { name: 'Store revenue' })).toBeHidden();

    /*
     * Reaching the route directly renders the shell and then nothing: the server refuses the request, so
     * no figure arrives. Hiding the nav item is a courtesy; this is the part that matters.
     */
    await page.goto('/commerce');
    await expect(page.getByRole('alert')).toBeVisible({ timeout: 20_000 });
  });
});
