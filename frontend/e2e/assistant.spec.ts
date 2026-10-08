import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * The assistant.
 *
 * Nothing here is a write, so nothing is named per project or retry: the whole surface is a read, and two browser
 * projects asking the same questions of the same seeded data get the same answers.
 */
async function openAssistant(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/ai-assistant');
}

async function chooseFirstClient(page: Page): Promise<void> {
  await page.getByRole('group', { name: 'Client' }).getByRole('button').first().click();
}

test.describe('the assistant', () => {
  test('says what it does and does not do before it answers anything', async ({ page }) => {
    await openAssistant(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Assistant' })).toBeVisible({
      timeout: 30_000,
    });

    /*
     * This is the one surface where a reader's default assumption — that a model worked the figures out — is
     * exactly wrong, so the correction arrives before the answer rather than after it.
     */
    await expect(page.getByText(/Every figure is computed by the product/)).toBeVisible();
    await expect(page.getByText(/No rate is quoted from fewer than 30 events/)).toBeVisible();
    await expect(page.getByText(/Nothing here changes anything/)).toBeVisible();
  });

  test('requires a client rather than averaging across all of them', async ({ page }) => {
    await openAssistant(page);

    /* A cost per click averaged over unrelated accounts is a number with no owner. */
    await expect(page.getByText('Pick a client')).toBeVisible({ timeout: 30_000 });
  });

  test('shows every figure with the evidence behind it', async ({ page }) => {
    await openAssistant(page);
    await chooseFirstClient(page);

    /* `PeriodShapeAnalyser` always speaks, so an empty list can never read as a clean bill of health. */
    await expect(page.getByText(/What the period spent/).first()).toBeVisible({ timeout: 30_000 });

    const evidence = page.getByRole('region', { name: 'The figures behind this' }).first();
    await expect(evidence).toBeVisible();
    await expect(evidence).toContainText('analytics totals');
  });

  test('tells the reader the figures are not production data', async ({ page }) => {
    await openAssistant(page);
    await chooseFirstClient(page);

    /*
     * Every provider in this repository is awaiting an authorization it cannot grant itself, so these are
     * sandbox figures — and the answer says so rather than reading as an analysis of a client's spend.
     */
    await expect(page.getByText('Sandbox').first()).toBeVisible({ timeout: 30_000 });
  });

  test('says the product wrote the prose, because no model is configured', async ({ page }) => {
    await openAssistant(page);
    await chooseFirstClient(page);

    /*
     * BLOCKED_EXTERNAL on an API key. The assistant works without one: a missing model costs a reader better
     * prose and nothing else, and the attribution says which they have.
     */
    await expect(page.getByText(/Written by digitalSmart from the findings/)).toBeVisible({
      timeout: 30_000,
    });
  });

  test('answers a question it understands and refuses one it does not', async ({ page }) => {
    await openAssistant(page);
    await chooseFirstClient(page);

    await page.getByRole('button', { name: 'Ask', exact: true }).first().click();

    /*
     * Asked by clicking one of the published examples, in the server's own words. A closed list of questions is
     * only usable if it is published, and this is the test that it is published *and* that the words on screen
     * are the words the matcher recognises.
     */
    const understood = page.getByRole('region', { name: 'Questions it understands' });
    await expect(understood).toBeVisible({ timeout: 30_000 });
    await understood.getByRole('button', { name: 'How much did we spend last month?' }).click();

    await expect(page.getByText(/Taken as: spend/)).toBeVisible({ timeout: 30_000 });

    // And a question it does not recognise is refused rather than answered approximately.
    const asking = page.getByRole('region', { name: 'Ask about this client' });
    await asking.getByLabel('Ask about this client').fill('Write me a poem about Riyadh');
    await asking.getByRole('button', { name: 'Ask', exact: true }).click();

    await expect(page.getByText(/That question was not understood/)).toBeVisible({
      timeout: 30_000,
    });
  });

  test('publishes what it does not do yet instead of offering an empty tab', async ({ page }) => {
    await openAssistant(page);
    await chooseFirstClient(page);

    const deferred = page.getByRole('region', { name: 'Not built yet' });
    await expect(deferred).toBeVisible({ timeout: 30_000 });
    await expect(deferred).toContainText('media planner');

    await expect(page.getByRole('button', { name: 'Plan', exact: true })).toHaveCount(0);
  });

  test('proposes a report and saves nothing', async ({ page }) => {
    await openAssistant(page);
    await chooseFirstClient(page);

    await page.getByRole('button', { name: 'Report', exact: true }).click();

    await expect(page.getByText(/Nothing has been saved/)).toBeVisible({ timeout: 30_000 });
    /* Internal, always: a client-safe report is one somebody has read as the client would. */
    await expect(page.getByText(/Proposed as internal/)).toBeVisible();
  });

  test('breaks a finding down by platform when asked', async ({ page }) => {
    await openAssistant(page);
    await chooseFirstClient(page);

    await expect(page.getByText(/What the period spent/).first()).toBeVisible({ timeout: 30_000 });
    await page.getByRole('button', { name: 'Break this down by platform' }).first().click();

    /* A rate that moved because the mix moved is not a rate that moved, and a total cannot tell them apart. */
    await expect(
      page.getByRole('region', { name: 'The figures behind this' }).first(),
    ).toContainText('analytics by platform', { timeout: 30_000 });
  });

  test('keeps the assistant away from a role without the permission', async ({ page }) => {
    await openAssistant(page, ACCOUNTS.creative);

    await expect(page.getByText('The assistant is not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openAssistant(page, ACCOUNTS.manager);

    await expect(page.getByRole('heading', { level: 1, name: 'Assistant' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'المساعد' })).toBeVisible();
  });
});
