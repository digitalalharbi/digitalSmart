import { expect, test } from '@playwright/test';
import { ACCOUNTS, DEMO_PASSWORD, signIn } from './support/accounts';

test.describe('authentication', () => {
  test('sends an anonymous visitor to sign in', async ({ page }) => {
    await page.goto('/clients');

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Sign in to digitalSmart' })).toBeVisible();
  });

  test('rejects wrong credentials without revealing whether the account exists', async ({
    page,
  }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill(ACCOUNTS.owner);
    await page.getByLabel('Password').fill('definitely-not-the-password');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('alert')).toContainText('do not match our records');
    await expect(page).toHaveURL(/\/login$/);
  });

  test('signs in and lands in the workspace', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);

    await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Account and workspace' })).toContainText(
      'DEMO — Smart Code Agency',
    );
  });

  test('keeps the session across a reload', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.reload();

    await expect(page.getByRole('heading', { level: 1, name: 'Overview' })).toBeVisible();
  });

  test('signs out and blocks the back route', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);

    await page.getByRole('button', { name: 'Account and workspace' }).click();
    await page.getByRole('menuitem', { name: 'Sign out' }).click();

    await expect(page).toHaveURL(/\/login$/);

    await page.goto('/clients');
    await expect(page).toHaveURL(/\/login$/);
  });

  test('validates the form before calling the API', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('not-an-email');
    await page.getByLabel('Password').fill(DEMO_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByText('Enter a valid email address.')).toBeVisible();
  });
});
