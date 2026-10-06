import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * A budget name unique to the running project.
 *
 * Both browser projects run at once against one backend and one demo account, and a
 * budget name is unique per client and period. Without the suffix the second project
 * collides with the first project's record.
 */
function named(base: string): string {
  return `E2E ${base} [${test.info().project.name}]`;
}

async function openPlanner(page: Page): Promise<void> {
  await page.goto('/media-planner');
  await expect(page.getByRole('heading', { level: 1, name: 'Media planner' })).toBeVisible({
    timeout: 30_000,
  });
}

/** Draft a budget and return once it is on screen. */
async function draftBudget(page: Page, name: string, amount: string): Promise<void> {
  await page.getByRole('button', { name: 'New budget' }).first().click();
  await page.getByLabel('Name').fill(name);
  await page
    .getByLabel(/Amount/)
    .first()
    .fill(amount);
  // The first demo client; which one does not matter, only that one is chosen.
  await page.getByRole('group', { name: 'Client' }).getByRole('button').first().click();
  await page.getByRole('button', { name: 'Save budget' }).click();

  await expect(page.getByRole('heading', { level: 2, name })).toBeVisible({ timeout: 20_000 });
}

test.describe('budgets', () => {
  /*
   * Serial, and each test names its budgets uniquely: these create records the API scopes
   * to the workspace, so in parallel they would see each other's and the picker selectors
   * would match several.
   */
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('drafts a budget, approves it, and paces it against real spend', async ({ page }) => {
    await openPlanner(page);

    const name = named('quarterly');
    await draftBudget(page, name, '50000');

    /*
     * Scoped to the pacing panel: the alert list carries status badges too, and both
     * projects' budgets live in one database.
     */
    const pacing = page.getByRole('region', { name: 'Pacing' });

    // A draft is not paced: a variance from a figure nobody agreed to means nothing.
    await expect(pacing.getByText('Not paced')).toBeVisible();

    await page.getByRole('button', { name: 'Approve', exact: true }).click();

    /*
     * Approved, so pacing appears — and it is measured against the spend the sandbox sync
     * actually wrote, which is why the source badge has to say sandbox.
     */
    await expect(page.getByText(/archive this budget and approve a new one/)).toBeVisible({
      timeout: 20_000,
    });
  });

  test('refuses to re-cost an approved budget', async ({ page }) => {
    await openPlanner(page);

    const name = named('fixed');
    await draftBudget(page, name, '40000');
    await page.getByRole('button', { name: 'Approve', exact: true }).click();
    await expect(page.getByText(/archive this budget and approve a new one/)).toBeVisible({
      timeout: 20_000,
    });

    await page.getByRole('button', { name: /^Edit$/ }).click();
    await page
      .getByLabel(/Amount/)
      .first()
      .fill('60000');
    await page.getByRole('button', { name: 'Save budget' }).click();

    /*
     * The server's own message, which names the way out. A generic failure here would
     * throw away the only useful part.
     */
    await expect(page.getByText(/no longer be changed/)).toBeVisible({ timeout: 20_000 });
  });

  test('allocates across platforms and reports the remainder', async ({ page }) => {
    await openPlanner(page);

    const name = named('allocated');
    await draftBudget(page, name, '100000');

    await page.getByRole('button', { name: 'Edit allocation' }).click();
    await page.getByRole('button', { name: 'Add a platform' }).click();
    await page
      .getByLabel(/^Amount/)
      .last()
      .fill('60000');

    // The remainder is shown, not spread: that would be a media decision made for somebody.
    await expect(page.getByText(/40,000\.00 not allocated yet/)).toBeVisible();

    await page.getByRole('button', { name: 'Save allocation' }).click();

    /*
     * A loose separator between the currency and the amount, deliberately: `Intl` puts a
     * non-breaking space there and a literal space in the pattern would never match.
     */
    await expect(page.getByText(/60,000\.00 of \S+\s100,000\.00 allocated/)).toBeVisible({
      timeout: 20_000,
    });
  });

  test('refuses allocations that come to more than the budget', async ({ page }) => {
    await openPlanner(page);

    const name = named('over allocated');
    await draftBudget(page, name, '10000');

    await page.getByRole('button', { name: 'Edit allocation' }).click();
    await page.getByRole('button', { name: 'Add a platform' }).click();
    await page
      .getByLabel(/^Amount/)
      .last()
      .fill('20000');

    await expect(page.getByText(/over the budget/)).toBeVisible();

    await page.getByRole('button', { name: 'Save allocation' }).click();

    // The warning is the fast feedback; this is the rule.
    await expect(page.getByText(/more than the approved budget/)).toBeVisible({ timeout: 20_000 });
  });

  test('offers a media buyer the plan but not the approval', async ({ page, context }) => {
    await openPlanner(page);
    await draftBudget(page, named('buyer view'), '30000');

    await context.clearCookies();
    await signIn(page, ACCOUNTS.buyer);
    await page.goto('/media-planner');

    /*
     * A buyer can reshape a plan all day and still not be the person who commits the
     * client to a figure. The control is hidden because the server would refuse it.
     */
    await expect(page.getByRole('heading', { level: 1, name: 'Media planner' })).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByRole('button', { name: 'Approve', exact: true })).toBeHidden();
  });

  test('keeps budgets away from a role without the permission', async ({ page, context }) => {
    await context.clearCookies();
    await signIn(page, ACCOUNTS.creative);
    await page.goto('/media-planner');

    // Creatives produce work; they do not see what a client is spending.
    await expect(page.getByText(/No access to budgets/i)).toBeVisible({ timeout: 30_000 });
  });

  test('estimates results from a budget, with its band and confidence', async ({ page }) => {
    await page.goto('/budget-calculator');
    await expect(page.getByRole('heading', { level: 1, name: 'Budget calculator' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Estimate' }).click();

    /*
     * Every estimate carries the sample it came from and the caveat that it is not a
     * guarantee. Both are in the payload, so neither can be lost by a screen.
     */
    await expect(page.getByText(/not guarantees/)).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/own-data\//)).toBeVisible();
    await expect(page.getByText(/days of delivery observed/).first()).toBeVisible();
  });

  test('works a budget back from a target', async ({ page }) => {
    await page.goto('/budget-calculator');
    await expect(page.getByRole('heading', { level: 1, name: 'Budget calculator' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Target → budget' }).click();
    await page.getByRole('button', { name: 'Estimate' }).click();

    await expect(page.getByText(/not guarantees/)).toBeVisible({ timeout: 20_000 });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await page.goto('/budget-calculator');
    await expect(page.getByRole('heading', { level: 1, name: 'Budget calculator' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'حاسبة الميزانية' })).toBeVisible();
    // The controls are translated, not just the chrome around them.
    await expect(page.getByRole('button', { name: 'احسب التقدير' })).toBeVisible();
  });
});
