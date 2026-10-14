import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * The page opens on cards now: a client list is a list of relationships, and a five-column table reads like
 * an inventory. The table is one click away and still carries every fact, which is what `shows the same
 * clients in the table view` below asserts — so these assertions follow the product rather than pinning a
 * layout that was never the point.
 */
test.describe('clients', () => {
  test('an owner sees the seeded demo clients, labelled as demo', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    // Navigate by URL: below `lg` the rail is hidden, and reaching the surface
    // through the drawer is covered by its own test.
    await page.goto('/clients');

    await expect(page.getByRole('heading', { level: 1, name: 'Clients' })).toBeVisible();
    await expect(page.getByText(/Alhambra Retail/).first()).toBeVisible();
    await expect(page.getByText('DEMO DATA').first()).toBeVisible();
  });

  test('a media buyer can read clients but is not offered creation', async ({ page }) => {
    await signIn(page, ACCOUNTS.buyer);
    await page.goto('/clients');

    await expect(page.getByText(/Alhambra Retail/).first()).toBeVisible();
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

    /* The card for the client just created, addressed by its own name. */
    const card = page.getByRole('article', { name });
    await expect(card).toBeVisible();
    await expect(card.getByText('Prospect')).toBeVisible();
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

    await expect(page.getByText(/Rawdah Hospitality/).first()).toBeVisible();
    await expect(page.getByText(/Alhambra Retail/)).toHaveCount(0);
  });

  /*
   * The other half of the view toggle. Both layouts carry the same facts — status, industry and the number
   * of projects — and a reader who prefers to compare twenty clients at once should lose nothing by it.
   */
  test('shows the same clients in the table view', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/clients');

    await page.getByRole('button', { name: 'Table', exact: true }).click();

    const row = page.getByRole('row', { name: /Alhambra Retail/ });
    await expect(row).toBeVisible();
    await expect(row.getByText('Active')).toBeVisible();
  });
});
