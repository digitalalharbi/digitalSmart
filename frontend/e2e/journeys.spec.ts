import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * One working day per role, against the sandbox agency.
 *
 * These run only when the stack was built with `E2E_AGENCY=1` — sixty clients, their campaigns, budgets, leads,
 * stores, creative, approvals and tracking — because a journey through an empty workspace proves nothing about a
 * morning at an agency. Each one starts where that person starts (their own home), does what they would do next,
 * and ends on something they could not have done in somebody else's seat.
 */
test.skip(
  process.env['E2E_AGENCY'] !== '1',
  'Needs the sandbox agency: E2E_AGENCY=1 scripts/e2e.sh',
);

const SLOW = { timeout: 60_000 };

test.describe('journey A — the media buyer', () => {
  test('finds their campaigns, opens one, and raises a task about it', async ({ page }) => {
    await signIn(page, ACCOUNTS.buyer);
    await page.goto('/');

    // Their own composition, not the agency's.
    await expect(page.getByRole('region', { name: 'My campaigns' })).toBeVisible(SLOW);

    await page.getByRole('link', { name: 'Open' }).first().click();
    await expect(page).toHaveURL(/\/campaigns\?mine=1/, SLOW);
    await expect(page.getByLabel('My projects only')).toBeChecked();

    const first = page.locator('tbody tr').first();
    await expect(first).toBeVisible(SLOW);
    const name = (await first.getByRole('link').first().textContent()) ?? '';
    await first.getByRole('link').first().click();

    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible(SLOW);

    const aside = page.getByRole('complementary', { name: 'Campaign context' });
    await aside.getByRole('button', { name: 'Add task' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Create task' }).click();

    // The task is about this campaign, so it is listed beside it.
    await expect(aside.getByText(`Review ${name}`)).toBeVisible(SLOW);
  });
});

test.describe('journey B — the project manager', () => {
  test('sees where the red is, opens a project, and works inside it', async ({ page }) => {
    await signIn(page, ACCOUNTS.manager);
    await page.goto('/');

    await expect(page.getByText('My projects').first()).toBeVisible(SLOW);

    const tile = page.getByRole('link', { name: /campaign/ }).first();
    await expect(tile).toBeVisible(SLOW);
    await tile.click();

    await expect(page).toHaveURL(/\/projects\//, SLOW);
    await expect(page.getByRole('tablist', { name: 'Areas of this project' })).toBeVisible(SLOW);

    for (const tab of ['Campaigns', 'Budget', 'Creative', 'Work', 'Reports']) {
      await page.getByRole('tab', { name: tab }).click();
      await expect(page.getByRole('tab', { name: tab })).toHaveAttribute('aria-selected', 'true');
    }

    await expect(page.getByRole('complementary', { name: 'Project context' })).toBeVisible();
  });
});

test.describe('journey C — finance', () => {
  test('reads the budgets, then plan against pace against forecast', async ({ page }) => {
    await signIn(page, ACCOUNTS.finance);
    await page.goto('/');

    await expect(page.getByText('Budgets at risk').first()).toBeVisible(SLOW);

    await page.goto('/budget-control');
    await expect(page.getByRole('heading', { level: 1, name: 'Budget control' })).toBeVisible(SLOW);
    await expect(page.getByText('Expected by today').first()).toBeVisible(SLOW);
    await expect(page.getByText('Projected at the end').first()).toBeVisible();

    // A finance seat does not run campaigns.
    await page.goto('/creative-hub');
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible(SLOW);
  });
});

test.describe('journey D — the creative', () => {
  test('sees the desk, switches the view, and opens a request', async ({ page }) => {
    await signIn(page, ACCOUNTS.creative);
    await page.goto('/');
    await expect(page.getByText('Creative pipeline').first()).toBeVisible(SLOW);

    await page.goto('/creative-hub');
    await expect(page.getByRole('region', { name: 'Creative desk' })).toBeVisible(SLOW);

    const views = page.getByRole('group', { name: 'View' });

    for (const view of ['Board', 'Table', 'Gallery']) {
      await views.getByRole('button', { name: view, exact: true }).click();
      await expect(views.getByRole('button', { name: view, exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
      );
    }

    await page.getByRole('list', { name: 'Gallery' }).getByRole('button').first().click();
    await expect(page.getByRole('heading', { level: 2 }).first()).toBeVisible(SLOW);
  });
});

test.describe('journey E — the client', () => {
  test('lands on their own portal, and the agency says no to everything else', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/');

    // Their own application, not the agency's.
    await expect(page).toHaveURL(/\/portal/, SLOW);
    await expect(page.getByRole('navigation', { name: 'Main navigation' })).toHaveCount(0);
    await expect(page.getByRole('navigation', { name: 'Your portal' })).toBeVisible(SLOW);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

    // The agency's API refuses them — the pages are not the control, the server is.
    const statuses = await page.evaluate(async () => {
      const out: number[] = [];

      for (const path of [
        '/api/campaigns/control',
        '/api/portfolio',
        '/api/budgets/control',
        '/api/members',
      ]) {
        out.push((await fetch(path, { credentials: 'include' })).status);
      }

      return out;
    });

    for (const status of statuses) {
      expect([401, 403]).toContain(status);
    }

    // And typing an agency address shows no agency figures, only a refusal.
    await page.goto('/campaigns');
    await expect(page.getByRole('article')).toHaveCount(0);
  });
});

test.describe('journey F — the executive', () => {
  test('goes from the agency to a client to a project to a campaign', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/');

    await expect(page.getByText('Managed spend').first()).toBeVisible(SLOW);

    // Agency → client: the ranking names every client and links to it.
    await page.goto('/portfolio');
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible(SLOW);
    await page.locator('a[href^="/clients/"]').first().click();
    await expect(page).toHaveURL(/\/clients\//, SLOW);

    // Client → project: the operational reading lists the projects.
    await page.getByRole('button', { name: 'Operational' }).click();
    await page.locator('a[href^="/projects/"]').first().click();
    await expect(page.getByRole('tablist', { name: 'Areas of this project' })).toBeVisible(SLOW);

    // Project → campaign.
    await page.getByRole('tab', { name: 'Campaigns' }).click();
    const campaign = page.locator('a[href^="/campaigns/"]').first();
    await expect(campaign).toBeVisible(SLOW);
    const name = (await campaign.textContent()) ?? '';
    await campaign.click();
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible(SLOW);
  });
});
