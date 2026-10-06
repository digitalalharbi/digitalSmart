import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Sign in and open the Lead Center.
 *
 * Per test rather than in a `beforeEach`, because one test needs a different account: signing in
 * twice in one browser context leaves a session the second attempt cannot replace.
 */
async function openLeads(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/leads');
}

test.describe('lead centre', () => {
  test('shows the pipeline and the counted taps without a rate between them', async ({ page }) => {
    await openLeads(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Lead Center' })).toBeVisible({
      timeout: 30_000,
    });

    const signals = page.getByRole('region', { name: 'Taps and forms begun' });
    await expect(signals).toBeVisible();

    /*
     * The refusal this surface exists for, asserted in the browser as well as in the unit tests:
     * taps and leads appear together and nothing divides one by the other. An agency quoting a
     * "tap-to-lead rate" would be quoting a figure with no denominator anybody can defend.
     */
    await expect(signals.getByRole('alert')).toContainText('no percentage is offered');
    await expect(signals.getByText('%')).toHaveCount(0);
  });

  test('explains why qualified is not a step in the funnel', async ({ page }) => {
    await openLeads(page);

    const pipeline = page.getByRole('region', { name: 'How far leads got' });
    await expect(pipeline).toBeVisible({ timeout: 30_000 });

    // The reason lives beside the chart, because the reader is who it protects.
    await expect(pipeline).toContainText('Qualified is not a step here');
  });

  test('offers every chart as a table', async ({ page }) => {
    await openLeads(page);

    const pipeline = page.getByRole('region', { name: 'How far leads got' });
    await expect(pipeline).toBeVisible({ timeout: 30_000 });

    /*
     * A canvas tells a screen reader nothing and a tooltip needs a pointer, so the table is the
     * only route to a value for some readers — and it is in the open rather than behind a
     * setting, because plenty of readers simply prefer it.
     */
    await pipeline.getByRole('button', { name: 'Table', exact: true }).click();

    await expect(pipeline.getByRole('row', { name: /Arrived/ })).toBeVisible();
    await expect(pipeline.getByRole('columnheader', { name: 'As a share of' })).toBeVisible();
  });

  test('keeps the Lead Center away from a role without the permission', async ({ page }) => {
    await openLeads(page, ACCOUNTS.creative);

    /*
     * The surface carries prospects' names and phone numbers. A creative has no business with
     * them, and the page says so rather than rendering empty — a blank page reads as broken and
     * invites somebody to ask an engineer about it.
     */
    await expect(page.getByText('The Lead Center is not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openLeads(page, ACCOUNTS.manager);

    await expect(page.getByRole('heading', { level: 1, name: 'Lead Center' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(
      page.getByRole('heading', { level: 1, name: 'مركز العملاء المحتملين' }),
    ).toBeVisible();

    /*
     * The caveats are translated too, not just the chrome. A chart whose warnings are in English
     * on an Arabic page is a chart whose warnings go unread, which is worse than not having them.
     */
    await expect(page.getByText('«مؤهَّل» ليست مرحلة هنا')).toBeVisible();
  });
});
