import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Open a chart surface and wait for it to be ready.
 *
 * These two routes are code-split: the charting engine is a 209 kB chunk fetched on
 * first visit, on top of the analytics query itself. Against a single-process dev
 * server shared by every worker that can exceed the default assertion timeout — not
 * because the application is slow (the queries measure about 30 ms) but because the
 * harness is the bottleneck. So the wait for the surface to appear is explicit and
 * generous, and every assertion after it uses the normal timeout.
 */
async function openSurface(page: Page, path: string, region: string): Promise<void> {
  await page.goto(path);
  await expect(page.getByRole('region', { name: region })).toBeVisible({ timeout: 30_000 });
}

/*
 * Run against the demo workspace, whose performance data comes from the real sync
 * over SANDBOX fixtures. That means these assertions exercise the whole path —
 * adapter, mapper, store, SQL aggregation, API, chart — rather than a stub, and a
 * break anywhere along it fails here.
 */

test.describe('analytics', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('shows a scorecard and warns that the figures are sandbox', async ({ page }) => {
    await openSurface(page, '/analytics', 'Key figures');

    await expect(page.getByRole('heading', { level: 1, name: 'Analytics' })).toBeVisible();

    /*
     * The most important assertion in this file. Sandbox figures reaching a client
     * labelled as real performance is the failure the whole source-state mechanism
     * exists to prevent, so the warning is asserted before the numbers.
     */
    const notice = page.getByRole('status').first();
    await expect(notice).toContainText('Sandbox');
    await expect(notice).toContainText(/Do not share them with a client/i);

    const scorecard = page.getByRole('region', { name: 'Key figures' });
    await expect(scorecard.getByText('Spend', { exact: true })).toBeVisible();
    await expect(scorecard.getByText('ROAS', { exact: true })).toBeVisible();
  });

  test('renders real figures, not placeholders', async ({ page }) => {
    await openSurface(page, '/analytics', 'Key figures');

    const scorecard = page.getByRole('region', { name: 'Key figures' });
    const spendTile = scorecard.getByRole('button', { name: /Spend/ });

    // A currency amount with a thousands separator and cents — i.e. a real total
    // from the fact table, not a skeleton and not a dash.
    await expect(spendTile).toContainText(/[\d,]+\.\d{2}/);
  });

  test('breaks spend down by campaign with the platform ids kept', async ({ page }) => {
    await openSurface(page, '/analytics', 'Key figures');

    const table = page.getByRole('table');
    await expect(table).toBeVisible();

    // The campaign name as the platform has it, and the platform's own id beside
    // it so an operator can reconcile the two screens.
    await expect(table.getByText(/SANDBOX —/).first()).toBeVisible();
    await expect(table.getByText(/^\d{10,}$/).first()).toBeVisible();
  });

  test('changes the period and keeps the page in place', async ({ page }) => {
    await openSurface(page, '/analytics', 'Key figures');

    const scorecard = page.getByRole('region', { name: 'Key figures' });
    await expect(scorecard.getByText('Spend', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: '7 days' }).click();

    // Figures stay on screen through the refetch rather than collapsing to
    // skeletons on every interaction.
    await expect(scorecard.getByText('Spend', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: '7 days' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
  });

  test('plots the figure a reader selects', async ({ page }) => {
    await openSurface(page, '/analytics', 'Key figures');

    const scorecard = page.getByRole('region', { name: 'Key figures' });
    await scorecard.getByRole('button', { name: /Clicks/ }).click();

    await expect(page.getByText('Clicks over time')).toBeVisible();
  });

  test('filters to one platform through its identifier chip', async ({ page }) => {
    await openSurface(page, '/analytics', 'Key figures');

    const scorecard = page.getByRole('region', { name: 'Key figures' });
    const spendTile = scorecard.getByRole('button', { name: /Spend/ });
    await expect(spendTile).toContainText(/[\d,]+\.\d{2}/);

    const everything = (await spendTile.textContent()) ?? '';

    await page.getByRole('button', { name: 'Meta Ads' }).click();

    // One platform's spend is necessarily less than three platforms' spend, so the
    // tile must change — a filter that leaves the total identical is not applied.
    await expect(spendTile).not.toHaveText(everything);
  });

  test('refuses the surface to a role without the permission', async ({ page, context }) => {
    await context.clearCookies();
    await signIn(page, ACCOUNTS.creative);

    await page.goto('/analytics');

    /*
     * Creatives produce work; they do not see client spend. The refusal is
     * server-side, so this would still fail if the nav item were merely hidden.
     */
    await expect(
      page.getByText(/role does not include access to performance analytics/i),
    ).toBeVisible();
    await expect(page.getByRole('region', { name: 'Key figures' })).toBeHidden();
  });
});

test.describe('campaign explorer', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('drills from a platform down to its ads', async ({ page }) => {
    await openSurface(page, '/campaigns/explore', 'Key figures');

    const trail = page.getByRole('navigation', { name: /Drill-down trail/i });
    await expect(trail.getByRole('button', { name: /All platforms/ })).toBeVisible();

    const table = page.getByRole('table');
    await expect(table).toBeVisible();

    // Platform → campaign.
    await table.getByText('Meta Ads').first().click();
    await expect(trail.getByRole('button', { name: 'Meta Ads' })).toBeVisible();
    await expect(table.getByText(/SANDBOX —/).first()).toBeVisible();

    // Campaign → ad group.
    await table
      .getByText(/SANDBOX —/)
      .first()
      .click();
    await expect(trail.getByRole('button').nth(2)).toBeVisible();

    // And back out through the trail, which must restore the platform list.
    await trail.getByRole('button', { name: /All platforms/ }).click();
    await expect(table.getByText('Meta Ads')).toBeVisible();
  });
});
