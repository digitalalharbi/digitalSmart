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
    /* The claim is an ⓘ beside the source badge: in the page for assistive technology, shown on hover or focus. */
    await expect(page.getByText(/invoiced this money|measurement of sales/)).toBeAttached();
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

    await expect(page.getByText(/There is no combined figure/)).toBeAttached();
  });

  test('splits the period into new, returning and unclassifiable customers', async ({ page }) => {
    await openCommerce(page);

    const split = page.getByRole('region', { name: 'New and returning customers' });

    await expect(split).toBeVisible({ timeout: 20_000 });

    /*
     * All three buckets, including the one that says the product cannot tell. A split that only named the
     * third bucket when it was large would teach a reader that it never happens.
     */
    await expect(
      split.getByRole('rowheader', { name: 'Started before this store was connected' }),
    ).toBeVisible();

    /*
     * Whichever way the seeded period falls, the panel is consistent with itself: either there is a headline
     * share and no withholding sentence, or there is a sentence and no percentage anywhere in the split.
     */
    const share = split.getByTestId('customer-mix-share');

    if ((await share.count()) > 0) {
      await expect(share).toContainText('new customers');
    } else {
      await expect(
        split.getByText(/no mix to report|before this store was connected, so/),
      ).toBeVisible();
      await expect(split.getByText('%')).toHaveCount(0);
    }
  });

  /*
   * The half of this module that did not exist. A workspace could connect Salla on the integrations page and
   * nothing could say which client a merchant belonged to, so every webhook delivery was refused as an unknown
   * store and every figure on this page was empty.
   *
   * The demo seeds its store through the same use case, so what this asserts is the state a real owner reaches.
   */
  test('says which stores it reads, and reads one on request', async ({ page }) => {
    await openCommerce(page);

    const stores = page.getByRole('list', { name: 'Connected stores' });
    await expect(stores).toBeVisible({ timeout: 30_000 });

    const row = stores.getByRole('listitem').filter({ hasText: 'SANDBOX — Noon Retail' });
    /* The currency travels with the store, because every amount read from it is stored in that currency. */
    await expect(row.getByText('SAR')).toBeVisible();
    await expect(row.getByText(/last read|no orders came back/)).toBeVisible();

    await row.getByRole('button', { name: 'Read now' }).click();

    /*
     * What the read brought back, including that it is sandbox. The fixtures are re-read over the restatement
     * window, so the figures are stable and the upsert is what keeps them from multiplying.
     */
    await expect(page.getByText(/Read SANDBOX — Noon Retail/)).toBeVisible({
      timeout: 30_000,
    });
  });

  /* Every merchant the connection can see, with the ones already taken named rather than hidden. */
  test('offers the merchants the connection can read, and names the ones taken', async ({
    page,
  }) => {
    await openCommerce(page);

    await page.getByRole('button', { name: 'Connect a store' }).click();

    await expect(page.getByText(/Already read for/)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('button', { name: 'Connect it' })).toHaveCount(0);
  });

  test('offers a reader of the figures no setup controls', async ({ page }) => {
    await openCommerce(page, ACCOUNTS.buyer);

    /*
     * The panel is there — "which store is this figure from" is part of reading the figure — and the controls
     * are not: connecting a store and spending the workspace's credentials on a platform are workspace setup.
     *
     * The demo's buyer is on no project, which is that account's own demonstration of where visibility comes
     * from, so what they see here is the empty state rather than the store the owner sees.
     */
    await expect(page.getByRole('heading', { level: 2, name: 'Stores we read' })).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByRole('button', { name: 'Connect a store' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Read now' })).toHaveCount(0);
  });

  test('does not offer store revenue to a creative', async ({ page }) => {
    await signIn(page, ACCOUNTS.creative);

    // The rail only exists at desktop width, so assert on it there.
    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(page.getByRole('link', { name: 'Store revenue' })).toBeHidden();

    /*
     * Reaching the route directly renders the shell and then the reason: the server refuses the request, and
     * the page says the role does not reach store revenue rather than reporting a load failure a retry might
     * fix. Hiding the nav item is a courtesy; this is the part that matters.
     */
    await page.goto('/commerce');
    await expect(page.getByRole('alert')).toContainText(
      'does not include access to store revenue',
      {
        timeout: 20_000,
      },
    );
  });
});
