import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

test.describe('integrations', () => {
  test('lists every platform with an honest, unconnected status', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/integrations');

    await expect(page.getByRole('heading', { level: 1, name: 'Integrations' })).toBeVisible();

    // The reason nothing is connected is stated, not implied.
    await expect(page.getByText('Waiting on provider access')).toBeVisible();
    await expect(page.getByText('Meta Ads').first()).toBeVisible();
    await expect(page.getByText('ChatGPT Ads').first()).toBeVisible();
  });

  test('says a platform with no published API has nothing to offer', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/integrations');

    await expect(page.getByText(/No published API yet/)).toBeVisible();
  });

  test('warns never to enter a client password when collecting credentials', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/integrations');

    await page.getByRole('button', { name: 'Add credentials' }).first().click();

    await expect(page.getByText(/Never enter a client's platform password/)).toBeVisible();
    // Secrets are write-only: the field is a password input and starts empty.
    const secret = page.getByLabel('Client secret');
    await expect(secret).toHaveAttribute('type', 'password');
    await expect(secret).toHaveValue('');
  });

  test('a project manager cannot reach integrations at all', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/integrations');

    // A manager holds workspaces.view, so they can read the page but not change it.
    await expect(page.getByRole('heading', { level: 1, name: 'Integrations' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add credentials' })).toHaveCount(0);
  });

  test('a client viewer is refused outright', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/integrations');

    await expect(page.getByRole('alert')).toContainText('does not include access to integrations');
  });

  test('granting a project access is a deliberate, explained action', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/integrations');

    await expect(
      page.getByText(/A project reads an ad account only if it is granted here/),
    ).toBeVisible();
    await expect(page.getByText('Choose a project')).toBeVisible();
  });
});
