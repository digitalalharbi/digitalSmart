import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

test.describe('projects', () => {
  test('an owner sees every project in the workspace', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/projects');

    await expect(page.getByRole('heading', { level: 1, name: 'Projects' })).toBeVisible();
    await expect(page.getByRole('row', { name: /Always-on performance/ }).first()).toBeVisible();
  });

  test('an unassigned buyer is told why the list is empty', async ({ page }) => {
    await signIn(page, ACCOUNTS.buyer);
    await page.goto('/projects');

    // Not "no projects exist" — the workspace has several.
    await expect(page.getByText('You are not assigned to any project yet')).toBeVisible();
    await expect(page.getByText(/A project manager assigns people/)).toBeVisible();
  });

  test('a manager creates a project, which starts in planning', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/projects');

    const name = `E2E Project ${Date.now()}`;
    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Client').selectOption({ index: 1 });
    await page.getByLabel('Project name').fill(name);
    await page.getByRole('button', { name: 'Save' }).click();

    const row = page.getByRole('row', { name: new RegExp(name) });
    await expect(row).toBeVisible();
    await expect(row.getByText('Planning')).toBeVisible();
  });

  test('rejects an end date before the start date', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/projects');

    await page.getByRole('button', { name: 'New project' }).click();
    await page.getByLabel('Client').selectOption({ index: 1 });
    await page.getByLabel('Project name').fill('Backwards schedule');
    await page.getByLabel('Starts').fill('2026-06-01');
    await page.getByLabel('Ends').fill('2026-05-01');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByText('The end date cannot be before the start date.')).toBeVisible();
  });
});
