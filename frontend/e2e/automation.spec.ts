import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * Agents & Automation: a rule made from a template, its mode, its switch, and the record it leaves — the five tabs
 * a person moves between.
 */
const SLOW = { timeout: 30_000 };

test('makes a rule from a template, refuses Auto, switches it off, and logs who did what', async ({
  page,
}) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/automation');

  await expect(page.getByRole('heading', { level: 1, name: 'Rules' })).toBeVisible(SLOW);
  for (const tab of ['Agents', 'Routines', 'Action history', 'Change log']) {
    await expect(page.getByRole('link', { name: tab, exact: true })).toBeVisible();
  }

  const name = `E2E cut losers ${String(Date.now())}`;
  await page.getByRole('button', { name: 'Use template' }).nth(1).click();
  const drawer = page.getByRole('dialog');
  await drawer.getByLabel('Rule name').fill(name);

  // Auto is named and cannot be chosen.
  await expect(drawer.getByRole('button', { name: /^Auto/ })).toBeDisabled();
  await expect(drawer.getByRole('button', { name: /^Recommend/ })).toHaveAttribute(
    'aria-pressed',
    'true',
  );

  await drawer.getByRole('button', { name: 'Save rule' }).click();
  await expect(drawer).toBeHidden(SLOW);

  const row = page.getByRole('row').filter({ hasText: name });
  await expect(row).toBeVisible(SLOW);
  await expect(row.getByText('Recommend')).toBeVisible();
  await expect(row.getByText('Decrease budget 20%')).toBeVisible();

  await row.getByRole('switch').click();
  await expect(row.getByRole('switch')).toHaveAttribute('aria-checked', 'false', SLOW);

  await page.getByRole('link', { name: 'Change log', exact: true }).click();
  const entries = page.getByRole('row').filter({ hasText: name });
  await expect(entries.filter({ hasText: 'Rule created' })).toBeVisible(SLOW);
  await expect(entries.filter({ hasText: 'Rule disabled' })).toBeVisible();
});

test('shows the agents as workers and the routines with their next run', async ({ page }) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/automation/agents');

  for (const agent of [
    'Analyst',
    'Campaign agent',
    'Finance agent',
    'Creative agent',
    'Leads agent',
    'Reports agent',
    'Project operations',
  ]) {
    await expect(page.getByRole('button', { name: new RegExp(agent) })).toBeVisible(SLOW);
  }

  await page.getByRole('link', { name: 'Routines', exact: true }).click();
  await expect(page.getByText('Weekly client briefs')).toBeVisible(SLOW);
  await expect(page.getByText('Next run').first()).toBeVisible();
});
