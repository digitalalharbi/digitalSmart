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

  /*
   * Read-only on purpose: actually changing a demo account's password would break every other test in the suite,
   * which signs in with the same one. What is asserted is everything up to the irreversible click — that the
   * control exists where somebody would look for it, that it says what the change will do, and that it refuses
   * to send an obviously wrong form.
   */
  test('offers a password change in the account menu, and says what it will do', async ({
    page,
  }) => {
    await signIn(page, ACCOUNTS.owner);

    await page.getByRole('button', { name: /Account|الحساب/ }).click();
    await page.getByRole('menuitem', { name: 'Change your password' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();

    /* Said before it happens, because otherwise a reader wonders later why their phone signed itself out. */
    await expect(
      dialog.getByText(/Every other place you are signed in will be signed out/),
    ).toBeVisible();
    await expect(dialog.getByText(/This screen stays signed in/)).toBeVisible();

    /* And why the current password is asked for at all. */
    await expect(
      dialog.getByText(/a session can be left open on a machine that is not yours/),
    ).toBeVisible();

    /* A short password is refused in the browser rather than sent to be refused. */
    await dialog.getByLabel('New password*', { exact: true }).fill('short');
    await expect(dialog.getByText('Use at least 12 characters.')).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Change it' })).toBeDisabled();
  });

  test('validates the form before calling the API', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('not-an-email');
    await page.getByLabel('Password').fill(DEMO_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByText('Enter a valid email address.')).toBeVisible();
  });
});
