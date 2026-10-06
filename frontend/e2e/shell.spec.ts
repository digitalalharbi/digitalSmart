import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

test.beforeEach(async ({ page }) => {
  await signIn(page, ACCOUNTS.owner);
});

test.describe('application shell', () => {
  test('loads the overview and exposes primary navigation', async ({ page }) => {
    await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible();
    await expect(page).toHaveTitle(/digitalSmart/);
  });

  test('switches to Arabic and flips document direction', async ({ page }) => {
    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.getByRole('heading', { level: 1, name: 'نظرة عامة' })).toBeVisible();
  });

  test('switches appearance and persists it across a reload', async ({ page }) => {
    await page.getByRole('button', { name: 'Switch appearance' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  });

  test('navigates to a surface that a later phase delivers', async ({ page }) => {
    /*
     * Reports is still a placeholder. Analytics used to be the example here and
     * stopped being one in Phase 4 — exactly the drift this assertion is meant to
     * catch, so it moves to the next undelivered surface rather than being deleted.
     */
    await page.goto('/reports');

    await expect(page.getByRole('heading', { level: 1, name: 'Reports' })).toBeVisible();
    await expect(page.getByText('Planned for Phase 20')).toBeVisible();
    await expect(page.getByText(/delivered in a later phase/)).toBeVisible();
  });
});

test.describe('mobile navigation', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('opens the drawer, navigates, and closes itself', async ({ page }) => {
    /*
     * The rail itself is hidden at this width. Asserted on the navigation landmark
     * rather than on a link inside it: links to the same destination exist elsewhere
     * on the page — the overview card offers one — and this assertion is about the
     * rail, not about every anchor that happens to point at analytics.
     */
    await expect(page.getByRole('navigation', { name: 'Main navigation' })).toBeHidden();

    await page.getByRole('button', { name: 'Open navigation' }).click();
    const drawer = page.getByRole('dialog', { name: 'Main navigation' });
    await expect(drawer).toBeVisible();

    await drawer.getByRole('link', { name: 'Analytics' }).click();

    await expect(page.getByRole('heading', { level: 1, name: 'Analytics' })).toBeVisible();
    await expect(drawer).toBeHidden();
  });

  test('closes the drawer with Escape', async ({ page }) => {
    await page.getByRole('button', { name: 'Open navigation' }).click();
    await expect(page.getByRole('dialog', { name: 'Main navigation' })).toBeVisible();

    await page.keyboard.press('Escape');

    await expect(page.getByRole('dialog', { name: 'Main navigation' })).toBeHidden();
  });
});
