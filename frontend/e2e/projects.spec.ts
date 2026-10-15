import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { chooseClient } from './support/pickers';

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

  test('a manager creates a project, which starts as a draft', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/projects');

    const name = `E2E Project ${Date.now()}`;
    await page.getByRole('button', { name: 'New project' }).click();
    await chooseClient(page.getByRole('dialog'));
    await page.getByLabel('Project name').fill(name);
    await page.getByRole('button', { name: 'Save' }).click();

    const row = page.getByRole('row', { name: new RegExp(name) });
    await expect(row).toBeVisible();
    await expect(row.getByText('Draft')).toBeVisible();
  });

  /*
   * The access control for a client's data, end to end.
   *
   * Project membership is the only thing that grants sight of a client's work, and until this surface existed
   * the endpoints were there with nothing reaching them — so an administrator could not grant anybody access to
   * anything. The page showed a member count and no way to change it.
   *
   * On its own project with a run-unique name, so the demo's own assignments — which five other tests depend on
   * — are left exactly as they were, and the grant is removed again at the end.
   */
  test('grants and revokes access to a client’s work', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/projects');

    const name = `E2E Access ${Date.now()}`;
    await page.getByRole('button', { name: 'New project' }).click();
    await chooseClient(page.getByRole('dialog'));
    await page.getByLabel('Project name').fill(name);
    await page.getByRole('button', { name: 'Save' }).click();

    const row = page.getByRole('row', { name: new RegExp(name) });
    await expect(row).toBeVisible({ timeout: 30_000 });

    /* The member count is the control: it was the whole of that column and was read-only. */
    await row.getByRole('button', { name: 'Manage access' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();

    /*
     * A new project has nobody on it, and that is said rather than left as a blank list: nobody can reach this
     * client's work through it yet.
     */
    await expect(dialog.getByText('Nobody is on this project')).toBeVisible();

    const picker = dialog.getByRole('combobox', { name: 'Add somebody' });
    await expect(picker.getByRole('option', { name: /Demo Creative/ })).toHaveCount(1);

    await picker.selectOption({ label: 'Demo Creative · Creative' });
    await dialog.getByRole('button', { name: 'Grant access' }).click();

    /*
     * They are on it, in the role they hold in the agency. Scoped to the list of people rather than the whole
     * dialog: the picker's options carry the same names, and a bare text query matched both.
     */
    const people = dialog.getByRole('list', { name: 'People on this project' });

    await expect(people.getByText('Demo Creative')).toBeVisible({ timeout: 20_000 });
    await expect(dialog.getByText('Nobody is on this project')).toBeHidden();

    /* And the picker no longer offers them, because offering them again would be a no-op. */
    await expect(picker.getByRole('option', { name: /Demo Creative/ })).toHaveCount(0);

    /* Taking it away says what it does, and leaves the workspace as this test found it. */
    await expect(dialog.getByText(/takes away their sight of this client/)).toBeVisible();
    await people.getByRole('button', { name: 'Remove' }).click();
    await expect(dialog.getByText('Nobody is on this project')).toBeVisible({ timeout: 20_000 });
  });

  test('rejects an end date before the start date', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/projects');

    await page.getByRole('button', { name: 'New project' }).click();
    await chooseClient(page.getByRole('dialog'));
    await page.getByLabel('Project name').fill('Backwards schedule');
    await page.getByLabel('Starts').fill('2026-06-01');
    await page.getByLabel('Ends').fill('2026-05-01');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByText('The end date cannot be before the start date.')).toBeVisible();
  });
});
