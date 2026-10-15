import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

test.describe('campaign control center', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('opens on every campaign the owner may see, with a verdict beside the platform status', async ({
    page,
  }) => {
    await page.goto('/campaigns');

    await expect(
      page.getByRole('heading', { level: 1, name: 'Campaign Control Center' }),
    ).toBeVisible();
    await expect(page.getByRole('region', { name: 'Campaign summary' })).toBeVisible({
      timeout: 30_000,
    });
    const first = page.getByRole('article').first();
    await expect(first).toBeVisible({ timeout: 30_000 });
    // The platform's word and the product's, side by side.
    await expect(
      first.getByText(/Healthy|Watch|Needs attention|Critical|Inactive|Not enough data/).first(),
    ).toBeVisible();
  });

  test('keeps its filters in the address bar, so a filtered view can be shared', async ({
    page,
  }) => {
    await page.goto('/campaigns');
    await expect(page.getByRole('article').first()).toBeVisible({ timeout: 30_000 });

    await page.getByRole('region', { name: 'Filters' }).getByLabel('Search').fill('SANDBOX');
    await expect(page).toHaveURL(/q=SANDBOX/);

    await page.getByRole('button', { name: 'Table' }).click();
    await expect(page).toHaveURL(/view=table/);
    await expect(page.getByRole('table')).toBeVisible();

    await page.reload();
    await expect(page.getByRole('region', { name: 'Filters' }).getByLabel('Search')).toHaveValue(
      'SANDBOX',
    );
  });

  test('says so plainly when a filter matches nothing', async ({ page }) => {
    await page.goto('/campaigns?q=zzzz-no-such-campaign');

    await expect(page.getByText('No campaigns match these filters')).toBeVisible({
      timeout: 30_000,
    });
    await page.getByRole('button', { name: 'Clear all filters' }).click();
    await expect(page).not.toHaveURL(/q=/);
  });
});
