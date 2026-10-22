import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * The reports centre: one place for the library, what is live, what is scheduled, every frozen record, and every
 * link out of the agency. Each tab either lists what exists or says there is nothing — never a blank.
 */
test.describe('reports centre', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('opens on the library, with five ways of looking at the reports', async ({ page }) => {
    await page.goto('/reports');

    const tabs = page.getByRole('tablist', { name: 'Reports centre' });
    await expect(tabs).toBeVisible({ timeout: 30_000 });
    await expect(tabs.getByRole('tab')).toHaveText([
      'Library',
      'Live reports',
      'Scheduled',
      'Snapshots',
      'Shared links',
    ]);
    await expect(tabs.getByRole('tab', { name: 'Library' })).toHaveAttribute(
      'aria-selected',
      'true',
    );
  });

  test('every tab lists what exists or says there is nothing', async ({ page }) => {
    await page.goto('/reports');
    const tabs = page.getByRole('tablist', { name: 'Reports centre' });
    await expect(tabs).toBeVisible({ timeout: 30_000 });

    for (const name of ['Live reports', 'Scheduled', 'Snapshots', 'Shared links']) {
      await tabs.getByRole('tab', { name }).click();
      const panel = page.getByRole('tabpanel');

      await expect(
        panel
          .getByRole('table', { name })
          .or(
            panel.getByText(
              /No live reports|Nothing is scheduled|No snapshots yet|No links out of the agency/,
            ),
          ),
      ).toBeVisible({ timeout: 30_000 });
    }
  });

  test('moves between the tabs from the keyboard', async ({ page }) => {
    await page.goto('/reports');
    const tabs = page.getByRole('tablist', { name: 'Reports centre' });
    await expect(tabs).toBeVisible({ timeout: 30_000 });

    await tabs.getByRole('tab', { name: 'Library' }).focus();
    await page.keyboard.press('ArrowRight');

    await expect(tabs.getByRole('tab', { name: 'Live reports' })).toHaveAttribute(
      'aria-selected',
      'true',
    );
  });
});
