import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

test.describe('clients', () => {
  test('an owner sees the seeded demo clients, labelled as demo', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    // Navigate by URL: below `lg` the rail is hidden, and reaching the surface
    // through the drawer is covered by its own test.
    await page.goto('/clients');

    await expect(page.getByRole('heading', { level: 1, name: 'Clients' })).toBeVisible();
    await expect(page.getByRole('row', { name: /Alhambra Retail/ })).toBeVisible();
    await expect(page.getByText('DEMO DATA').first()).toBeVisible();
  });

  test('a media buyer can read clients but is not offered creation', async ({ page }) => {
    await signIn(page, ACCOUNTS.buyer);
    await page.goto('/clients');

    await expect(page.getByRole('row', { name: /Alhambra Retail/ })).toBeVisible();
    await expect(page.getByRole('button', { name: 'New client' })).toBeHidden();
  });

  test('a client viewer is not offered the clients surface at all', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);

    // The rail only exists at desktop width, so assert on it there.
    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(page.getByRole('link', { name: 'Clients' })).toBeHidden();

    // Reaching the route directly is refused by the server, and said plainly.
    await page.goto('/clients');
    await expect(page.getByRole('alert')).toContainText('does not include access to clients');
  });

  test('a manager creates a client, which starts as a prospect', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/clients');

    const name = `E2E Client ${Date.now()}`;
    await page.getByRole('button', { name: 'New client' }).click();
    await page.getByLabel('Client name').fill(name);
    await page.getByLabel('Industry').fill('Telecom');
    await page.getByRole('button', { name: 'Save' }).click();

    const row = page.getByRole('row', { name: new RegExp(name) });
    await expect(row).toBeVisible();
    await expect(row.getByText('Prospect')).toBeVisible();
  });

  test('surfaces server validation on the field that failed', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/clients');

    await page.getByRole('button', { name: 'New client' }).click();
    await page.getByLabel('Client name').fill('A');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('alert').first()).toBeVisible();
  });

  test('filters by status', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/clients');

    await page.getByRole('button', { name: 'Prospect', exact: true }).click();

    await expect(page.getByRole('row', { name: /Rawdah Hospitality/ })).toBeVisible();
    await expect(page.getByRole('row', { name: /Alhambra Retail/ })).toBeHidden();
  });
});
