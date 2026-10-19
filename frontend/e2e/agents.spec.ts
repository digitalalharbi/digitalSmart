import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * The agent layer as a person meets it: cards on Today with the agent that prepared them, the weekly briefs, a
 * campaign opened into its ads, rules that can only ask, the cross-platform builder that makes paused drafts, and a
 * one-time approval link that decides nothing it should not. Needs the sandbox agency.
 */
test.skip(
  process.env['E2E_AGENCY'] !== '1',
  'Needs the sandbox agency: E2E_AGENCY=1 scripts/e2e.sh',
);

const SLOW = { timeout: 60_000 };

test('Today carries the specialist agents’ cards, with what they rest on', async ({ page }) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/today');

  await page.getByRole('button', { name: /Creative$/ }).click();
  const creative = page
    .getByRole('article')
    .filter({ hasText: 'Prepared by the Creative agent' })
    .first();
  await expect(creative).toBeVisible(SLOW);
  await expect(creative.getByText('CTR this week')).toBeVisible();

  await page.getByRole('button', { name: /Leads$/ }).click();
  const leads = page
    .getByRole('article')
    .filter({ hasText: 'Prepared by the Leads agent' })
    .first();
  await expect(leads).toBeVisible(SLOW);
  await expect(leads.getByText('Qualified')).toBeVisible();
});

test('the weekly briefs are on Reports, per currency, with the days they cover', async ({
  page,
}) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/reports');

  const briefs = page.getByRole('region', { name: 'Weekly briefs' });
  await expect(briefs).toBeVisible(SLOW);
  await expect(briefs.getByText(/days reported/).first()).toBeVisible();
});

test('a campaign opens into its ad groups and ads, and their writes are shown as unavailable', async ({
  page,
}) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/campaigns');

  const card = page.getByRole('article').first();
  await card.getByRole('button', { name: 'Ad groups and ads' }).click();
  const groups = card.getByRole('list', { name: 'Ad groups' });
  await expect(groups).toBeVisible(SLOW);
  await expect(
    groups.getByRole('button', { name: /Pause — Not available/ }).first(),
  ).toBeDisabled();
});

test('a rule that opens tasks needs trust 3, and level 4 cannot be chosen', async ({ page }) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/automation');
  await page.getByRole('button', { name: 'New rule' }).click();

  await page.getByLabel('Then').selectOption('task');
  await expect(page.getByLabel('Trust')).toHaveValue('3');
  await expect(page.getByLabel('Trust').locator('option[value="4"]')).toBeDisabled();
  await expect(page.getByLabel('Trust').locator('option[value="2"]')).toBeDisabled();
});

test('the builder shows each platform’s limits and makes paused drafts with launch requests', async ({
  page,
}) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/campaigns/new');

  await page.getByLabel('Campaign name').fill('E2E Ramadan Sale');
  await page.getByRole('button', { name: 'Next' }).click();
  await page.getByLabel('Starts').fill('2026-11-01');
  await page.getByRole('button', { name: 'Next' }).click();
  await page.getByRole('button', { name: 'Next' }).click();
  await page.getByLabel('Headline').fill('Up to 40% off');
  await page.getByLabel('Primary text').fill('Shop before Eid.');
  await page.getByRole('button', { name: 'Next' }).click();
  await page.getByRole('button', { name: 'LinkedIn Ads' }).click();
  await page.getByRole('button', { name: 'Next' }).click();

  await expect(page.getByRole('table')).toBeVisible(SLOW);
  await expect(page.getByText(/Fixed age buckets/)).toBeVisible();

  await page.getByLabel('Project').selectOption({ index: 1 });
  await page.getByRole('button', { name: 'Create paused drafts' }).click();
  await expect(page.getByText(/paused drafts created/)).toBeVisible(SLOW);
});

test('a one-time approval link decides nothing when it is not a real link', async ({ page }) => {
  await page.goto(`/approve/${'A'.repeat(43)}`);
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByRole('heading', { name: /This link can.t be used/ })).toBeVisible(SLOW);
});
