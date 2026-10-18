import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { chooseClient } from './support/pickers';

/**
 * The agency-wide surfaces, end to end: they open, say what they are, and survive the states a real agency
 * is in — an empty store list, a fresh workspace with nothing to place, a saved view that is only one person's.
 *
 * Each runs on both browser projects (desktop and mobile Safari) from the config, so the same page is seen at
 * 375 pixels without a second test. Arabic and dark appearances are exercised at the end of the file, because
 * a layout that works in English and in light is only half checked.
 */
test.describe('agency surfaces', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('the portfolio opens with its key figures', async ({ page }) => {
    await page.goto('/portfolio');

    await expect(page.getByRole('heading', { level: 1, name: 'Agency portfolio' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Key figures' })).toBeVisible({
      timeout: 30_000,
    });
  });

  test('budget control opens, and its saved views start empty for a new person', async ({
    page,
  }) => {
    await page.goto('/budget-control');

    await expect(page.getByRole('heading', { level: 1, name: 'Budget control' })).toBeVisible();

    await page.getByRole('button', { name: 'Saved views' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByText('No saved views yet.')).toBeVisible();
  });

  test('saves the control center’s filters as a view, and finds it again', async ({ page }) => {
    await page.goto('/campaigns?q=SANDBOX');
    await expect(page.getByRole('heading', { level: 1, name: 'Campaigns' })).toBeVisible();

    const name = `E2E view ${String(Date.now())}`;

    await page.getByRole('button', { name: 'Saved views' }).click();
    await page.getByRole('textbox').fill(name);
    await page.getByRole('button', { name: 'Save view' }).click();

    await expect(page.getByRole('button', { name: new RegExp(`^${name}`) })).toBeVisible({
      timeout: 15_000,
    });
  });

  test('stores across clients opens, with a plain empty state when none are connected', async ({
    page,
  }) => {
    await page.goto('/commerce/stores');

    await expect(
      page.getByRole('heading', { level: 1, name: 'Stores across clients' }),
    ).toBeVisible();
    await expect(page.getByRole('table').or(page.getByText('No stores yet'))).toBeVisible({
      timeout: 30_000,
    });
  });

  test('the lead centre narrows to my leads and keeps working', async ({ page }) => {
    await page.goto('/leads');

    await expect(page.getByRole('heading', { level: 1, name: 'Lead Center' })).toBeVisible();
    await page.getByRole('button', { name: 'My leads' }).click();
    await expect(page.getByRole('button', { name: 'My leads' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    await expect(page.getByRole('region', { name: 'Lead summary' })).toBeVisible({
      timeout: 30_000,
    });
  });

  test('a project’s workspace opens from the projects list', async ({ page }) => {
    await page.goto('/projects');
    await expect(page.getByRole('heading', { level: 1, name: 'Projects' })).toBeVisible();

    /* Its own project, so the test does not depend on what other specs left behind. */
    const name = `E2E Workspace ${String(Date.now())}`;
    await page.getByRole('button', { name: 'New project' }).click();
    await chooseClient(page.getByRole('dialog'));
    await page.getByLabel('Project name').fill(name);
    await page.getByRole('button', { name: 'Save' }).click();

    /* The agency has well over a page of projects, so the new one is found by name rather than by position. */
    await page.getByRole('searchbox', { name: 'Search projects' }).fill(name);
    const row = page.getByRole('row', { name: new RegExp(name) });
    await expect(row).toBeVisible({ timeout: 30_000 });

    await row.getByRole('button', { name: new RegExp(`^Actions for ${name}`) }).click();
    await page.getByRole('menuitem', { name: 'Open project workspace' }).click();

    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('tablist', { name: 'Areas of this project' })).toBeVisible();
    await page.getByRole('tab', { name: 'Budget' }).click();
    await expect(page.getByRole('tab', { name: 'Budget' })).toHaveAttribute(
      'aria-selected',
      'true',
    );
    await expect(page.getByRole('complementary', { name: 'Project context' })).toBeVisible();
  });

  test('has no machine-detectable WCAG violation on the new global pages', async ({ page }) => {
    for (const path of ['/portfolio', '/budget-control', '/commerce/stores']) {
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30_000 });

      const { violations } = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

      expect(
        violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`),
        path,
      ).toEqual([]);
    }
  });

  test('reads in Arabic, right to left, with the same structure', async ({ page }) => {
    await page.goto('/portfolio');
    await expect(page.getByRole('heading', { level: 1, name: 'Agency portfolio' })).toBeVisible();

    await page.getByRole('button', { name: /Switch language|تبديل اللغة/ }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  });

  test('renders in the dark appearance without losing its content', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/budget-control');

    await expect(page.getByRole('heading', { level: 1, name: 'Budget control' })).toBeVisible();
  });
});
