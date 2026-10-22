import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Ads as a first-class screen: reachable from the Campaigns area without opening a campaign, pictures first with the
 * table one press away, and one press from an ad to its ad group and its campaign.
 */
test.describe('ads', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('opens from the Campaigns area on the pictures, and switches to the table', async ({
    page,
  }) => {
    await page.goto('/campaigns');
    await page.getByRole('link', { name: 'Ads', exact: true }).first().click();

    await expect(page).toHaveURL(/\/ads/);
    await expect(page.getByRole('heading', { level: 1, name: 'Ads' })).toBeVisible();
    await expect(page.getByRole('list', { name: 'Ads' }).locator('li').first()).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Table' }).click();
    await expect(page).toHaveURL(/view=table/);
    await expect(page.locator('tbody tr').first()).toBeVisible();
  });

  test('moves from an ad to its ad group and its campaign by the trail', async ({ page }) => {
    await page.goto('/ads');
    const link = page
      .getByRole('list', { name: 'Ads' })
      .locator('li')
      .first()
      .locator('a[href^="/ads/"]')
      .first();
    await expect(link).toBeVisible({ timeout: 30_000 });
    const name = (await link.textContent()) ?? '';
    await link.click();

    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible({ timeout: 30_000 });
    const trail = page.getByRole('navigation', { name: 'Where you are' });
    await expect(trail).toBeVisible();
    await trail.getByRole('link').nth(2).click();
    await expect(page).toHaveURL(/\/ad-groups\/[0-9a-f-]{36}/);
  });

  test('says plainly when a filter matches nothing', async ({ page }) => {
    await page.goto('/ads?status=rejected&verdict=weak&format=carousel');

    await expect(page.getByText('No ads match these filters')).toBeVisible({ timeout: 30_000 });
  });
});

/** Its own test, not inside the owner's describe: signing one account out under another's open page is a race. */
test('ads are closed to a role that may not read performance', async ({ page }) => {
  await signIn(page, ACCOUNTS.creative);
  /* As the SPA sends it: Sanctum only treats a request as the browser's own when it names where it came from. */
  const response = await page.request.get('/api/analytics/creatives?grain=ad', {
    headers: { Accept: 'application/json', Referer: page.url() },
  });

  expect(response.status()).toBe(403);
});
