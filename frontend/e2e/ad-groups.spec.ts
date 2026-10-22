import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Ad groups as a first-class screen: reachable from the navigation without opening a campaign, narrowed by the same
 * filters in the same address, and one press from an ad group to its campaign and back.
 */
test.describe('ad groups', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('opens from the Campaigns area, with each ad group beside its campaign', async ({
    page,
  }) => {
    await page.goto('/campaigns');
    await page.getByRole('link', { name: 'Ad Groups' }).first().click();

    await expect(page).toHaveURL(/\/ad-groups/);
    await expect(page.getByRole('heading', { level: 1, name: 'Ad Groups' })).toBeVisible();
    const first = page.locator('tbody tr').first();
    await expect(first).toBeVisible({ timeout: 30_000 });
    /* The head row is hidden on a phone, where each row is a card carrying its own column labels. */
    if ((page.viewportSize()?.width ?? 0) >= 640) {
      await expect(page.getByRole('columnheader', { name: 'Campaign health' })).toBeVisible();
    }
  });

  test('moves from an ad group to its campaign by the trail, and keeps its filters in the address', async ({
    page,
  }) => {
    await page.goto('/ad-groups');
    const first = page.locator('tbody tr').first();
    await expect(first).toBeVisible({ timeout: 30_000 });

    await page.getByRole('region', { name: 'Filters' }).getByLabel('Search').fill('SANDBOX');
    await expect(page).toHaveURL(/q=SANDBOX/);

    await page.getByRole('region', { name: 'Filters' }).getByLabel('Search').fill('');
    const link = page.locator('tbody tr').first().locator('a[href^="/ad-groups/"]').first();
    const name = (await link.textContent()) ?? '';
    await link.click();

    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible({ timeout: 30_000 });
    const trail = page.getByRole('navigation', { name: 'Where you are' });
    await expect(trail).toBeVisible();
    await trail.getByRole('link').nth(1).click();
    await expect(page).toHaveURL(/\/campaigns\/[0-9a-f-]{36}/);
  });

  test('says plainly when a filter matches nothing', async ({ page }) => {
    await page.goto('/ad-groups?q=zzzz-no-such-ad-group');

    await expect(page.getByText('No ad groups match these filters')).toBeVisible({
      timeout: 30_000,
    });
  });
});

/** Its own test, not inside the owner's describe: signing one account out under another's open page is a race. */
test('ad groups are closed to a role that may not read performance', async ({ page }) => {
  await signIn(page, ACCOUNTS.creative);
  /* As the SPA sends it: Sanctum only treats a request as the browser's own when it names where it came from. */
  const response = await page.request.get('/api/ad-groups', {
    headers: { Accept: 'application/json', Referer: page.url() },
  });

  expect(response.status()).toBe(403);
});
