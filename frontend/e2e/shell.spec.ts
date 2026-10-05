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
    // Analytics is still a placeholder; integrations stopped being one in
    // Phase 2, which is exactly the kind of drift this assertion should catch.
    await page.goto('/analytics');

    await expect(page.getByRole('heading', { level: 1, name: 'Analytics' })).toBeVisible();
    await expect(page.getByText('Planned for Phase 4')).toBeVisible();
    await expect(page.getByText(/delivered in a later phase/)).toBeVisible();
  });
});

test.describe('mobile navigation', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  test('opens the drawer, navigates, and closes itself', async ({ page }) => {
    // The rail is hidden at this width.
    await expect(page.getByRole('link', { name: 'Analytics' })).toBeHidden();

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
