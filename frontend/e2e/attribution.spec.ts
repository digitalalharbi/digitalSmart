import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

async function openAttribution(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/attribution');
}

test.describe('attribution', () => {
  test('offers a model it cannot compute, disabled and explained', async ({ page }) => {
    await openAttribution(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Attribution' })).toBeVisible({
      timeout: 30_000,
    });

    const unavailable = page.getByRole('button', { name: /Time decay/ });
    await expect(unavailable).toBeVisible();

    /*
     * Shown rather than hidden, with what is missing and what would unblock it. Hiding it is the
     * easier interface and the worse one: the question is then asked of an engineer every quarter.
     */
    await expect(unavailable).toBeDisabled();
    await expect(unavailable).toContainText('not available to this product');
    await expect(unavailable).toContainText('BigQuery');
  });

  test('says which models are claims and which is a record', async ({ page }) => {
    await openAttribution(page);

    const recorded = page.getByRole('button', { name: /As recorded on the order/ });
    await expect(recorded).toBeVisible({ timeout: 30_000 });

    // Exactly one of these figures can be quoted as what happened.
    await expect(recorded).toContainText('Recorded');
    await expect(page.getByRole('button', { name: /First recorded conversion/ })).toContainText(
      'Modelled',
    );
  });

  test('leads with how much of the period the figures account for', async ({ page }) => {
    await openAttribution(page);

    /*
     * An attributed figure read as a total is the specific way this page could mislead, so the share
     * it accounts for is a meter at the top rather than a footnote at the bottom.
     */
    await expect(page.getByRole('meter')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('No campaign can be credited')).toBeVisible();
  });

  test('offers the comparison as a table as well as a chart', async ({ page }) => {
    await openAttribution(page);

    const frame = page.getByRole('region', { name: 'What the choice of model is worth' });
    await expect(frame).toBeVisible({ timeout: 30_000 });

    await frame.getByRole('button', { name: 'Table', exact: true }).click();

    await expect(frame.getByRole('columnheader', { name: 'Disagreement' })).toBeVisible();
  });

  test('keeps attribution away from a role without the permission', async ({ page }) => {
    await openAttribution(page, ACCOUNTS.creative);

    /*
     * A view of a client's revenue against their spend. A creative has no business with it, and the
     * page says so rather than rendering empty.
     */
    await expect(page.getByText('Attribution is not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openAttribution(page, ACCOUNTS.manager);

    await expect(page.getByRole('heading', { level: 1, name: 'Attribution' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'نسبة النتائج' })).toBeVisible();
  });
});
