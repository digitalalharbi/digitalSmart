import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * One person, two workspaces. The demo owner owns the agency and is an administrator of a brand's own workspace. Switching
 * changes whose data the screen shows — and nothing of the previous workspace stays on it.
 */
test.describe('workspace switcher', () => {
  test('switches tenant, shows only that tenant’s clients, and switches back', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/clients');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('DEMO — Alhambra (own brand)')).toHaveCount(0);

    const switcher = page.getByRole('button', { name: /Switch workspace/ });

    /* The name is in the button's accessible name at every width; it is drawn only where the header has room. */
    await expect(switcher).toHaveAccessibleName(/DEMO — Smart Code Agency/);
    await switcher.click();
    await page.getByRole('menuitem', { name: /DEMO — Alhambra In-house/ }).click();

    await expect(page.getByRole('button', { name: /Switch workspace/ })).toHaveAccessibleName(
      /Alhambra In-house/,
      { timeout: 30_000 },
    );
    await page.goto('/clients');
    await expect(page.getByText('DEMO — Alhambra (own brand)').first()).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByText('DEMO — Najd Fintech')).toHaveCount(0);

    await page.getByRole('button', { name: /Switch workspace/ }).click();
    await page.getByRole('menuitem', { name: /DEMO — Smart Code Agency/ }).click();
    await expect(page.getByRole('button', { name: /Switch workspace/ })).toHaveAccessibleName(
      /Smart Code Agency/,
      { timeout: 30_000 },
    );
  });

  test('fits a 375px phone with the switcher open', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await signIn(page, ACCOUNTS.owner);
    await expect(page.getByRole('button', { name: /Switch workspace/ })).toBeVisible({
      timeout: 30_000,
    });
    await expectNoSidewaysScroll(page);
    await page.getByRole('button', { name: /Switch workspace/ }).click();
    await expect(page.getByRole('menuitem', { name: /Alhambra In-house/ })).toBeVisible();
    await expectNoSidewaysScroll(page);
  });
});
