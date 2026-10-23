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
    /* Dark is the default for a new visitor, so the test reads where it starts rather than assuming a side. */
    const html = page.locator('html');
    const before = (await html.getAttribute('data-theme')) === 'dark' ? 'dark' : 'light';
    const after = before === 'dark' ? 'light' : 'dark';

    await page.getByRole('button', { name: 'Switch appearance' }).click();
    await expect(html).toHaveAttribute('data-theme', after);

    await page.reload();
    await expect(html).toHaveAttribute('data-theme', after);
  });

  /*
   * There was a test here, and its subject is gone.
   *
   * It visited whichever nav surface was still a placeholder and asserted the phase number on it — Analytics,
   * then Reports, then the AI assistant, then Presentations. Every navigable surface is now implemented, so
   * there is no placeholder left to visit.
   *
   * It was replaced by something stronger rather than deleted: `src/app/router/routes.test.tsx` walks **every**
   * nav item and fails if any of them still falls through to the placeholder. One example became the whole set,
   * and a nav item added without a page now fails at build rather than when somebody clicks it.
   */
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

    /* The drawer lists the ten areas, not every page; Analytics opens on its first page. */
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
