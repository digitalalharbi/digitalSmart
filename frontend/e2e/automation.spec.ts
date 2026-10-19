import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
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

  const row = page.getByRole('article', { name });
  await expect(row).toBeVisible(SLOW);
  await expect(row.getByText('Recommend')).toBeVisible();
  await expect(row.getByText('Budget −20%')).toBeVisible();

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

/*
 * The engine end to end, against the sandbox data and no live credentials: a draft tested without writing anything,
 * a Recommend rule that raises a proposal and waits, an opposite rule that stands down, and the proposal decided by
 * a person. The engine runs here through its own command, exactly as the scheduler would run it.
 */
const runRules = (): void => {
  execSync('php artisan automation:run --force', {
    cwd: fileURLToPath(new URL('../../backend', import.meta.url)),
    /*
     * `scripts/e2e.sh` serves a database of its own and names it in E2E_DB; CI serves the one in `.env`. The command
     * must read the database the API is serving.
     */
    env:
      process.env['E2E_DB'] === undefined
        ? process.env
        : { ...process.env, DB_DATABASE: process.env['E2E_DB'] },
    stdio: 'pipe',
  });
};

/** The signed-in browser's own session, as the SPA sends it: same origin, XSRF header, JSON. */
async function api(
  page: Page,
  method: 'get' | 'post',
  path: string,
  data?: unknown,
): Promise<unknown> {
  const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
  const response = await page.request[method](path, {
    ...(data === undefined ? {} : { data }),
    headers: {
      Accept: 'application/json',
      'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''),
      Referer: page.url(),
    },
  });
  const body = await response.text();
  expect(response.ok(), body).toBe(true);
  return (JSON.parse(body) as { data?: unknown }).data;
}

async function decline(page: Page, title: string): Promise<void> {
  const waiting = page.getByRole('region', { name: 'Waiting for a decision', exact: true });
  await expect(waiting).toBeVisible(SLOW);
  await waiting.getByRole('button').filter({ hasText: title }).first().click();
  await page.getByRole('button', { name: 'Decline' }).click();
  await page.getByLabel(/Why it is being declined/).fill('E2E: declined so no budget moves');
  await page.getByRole('button', { name: 'Save' }).click();
  await expect(waiting.getByRole('button').filter({ hasText: title })).toHaveCount(0, SLOW);
}

const idOf = (data: unknown): string => String((data as { id?: string } | undefined)?.id ?? '');

test('tests a draft without changing anything', async ({ page }) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/automation');

  await page.getByRole('button', { name: 'Use template' }).first().click();
  const drawer = page.getByRole('dialog');
  await drawer.getByRole('button', { name: 'Test now' }).click();

  const result = drawer.getByRole('region', { name: 'Test now' });
  await expect(result.getByText('Matched')).toBeVisible(SLOW);
  await expect(result.getByText('Would act')).toBeVisible();
  await expect(result.getByText('Test only — nothing was changed or proposed.')).toBeVisible();
});

test('recommends and waits for a person, and an opposite rule stands down', async ({
  page,
}, testInfo) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/automation');
  await expect(page.getByRole('heading', { level: 1, name: 'Rules' })).toBeVisible(SLOW);

  const stamp = String(Date.now());
  const scale = `E2E scale ${stamp}`;
  const trim = `E2E trim ${stamp}`;
  /*
   * Each browser project takes its own sandbox campaign and its own pair of opposite actions, so the two runs in
   * parallel never stack proposals on one campaign: a budget raise against a cut where the campaign has a daily
   * budget, a pause against a raise where it does not — the raise stands down before it ever needs a budget.
   */
  const desktop = testInfo.project.name === 'chromium';
  const campaigns = (await api(page, 'get', '/api/campaigns/control?limit=500&days=7')) as {
    id: string;
    name: string;
  }[];
  const campaign = campaigns.find((c) =>
    c.name.includes(desktop ? 'Ramadan Sales' : 'Legacy Lead Form'),
  );
  if (campaign === undefined) throw new Error('The sandbox campaign is missing');
  const [act, opposite] = desktop
    ? (['propose_budget_increase', 'propose_budget_cut'] as const)
    : (['propose_pause', 'propose_budget_increase'] as const);
  const proposal = desktop ? `Raise daily budget — ${campaign.name}` : `Pause ${campaign.name}`;

  // A proposal left open on this campaign by an earlier attempt would be refused as a duplicate: decide it first.
  await page.goto('/approvals');
  const waiting = page.getByRole('region', { name: 'Waiting for a decision', exact: true });
  await expect(waiting).toBeVisible(SLOW);
  while ((await waiting.getByRole('button').filter({ hasText: campaign.name }).count()) > 0) {
    await decline(page, campaign.name);
  }

  const rule = {
    metric: 'spend',
    operator: 'above',
    threshold: 0.01,
    action_pct: 10,
    providers: ['meta'],
    campaign_ids: [campaign.id],
    cooldown_hours: 24,
  };

  const scaleId = idOf(
    await api(page, 'post', '/api/automation/rules', {
      ...rule,
      name: scale,
      action: act,
    }),
  );
  runRules();
  const trimId = idOf(
    await api(page, 'post', '/api/automation/rules', {
      ...rule,
      name: trim,
      action: opposite,
    }),
  );
  runRules();

  await page.goto('/automation/history');
  await expect(
    page
      .getByRole('row')
      .filter({ hasText: scale })
      .filter({ hasText: 'Waiting approval' })
      .first(),
  ).toBeVisible(SLOW);
  await expect(
    page
      .getByRole('row')
      .filter({ hasText: trim })
      .filter({ hasText: 'Conflict prevented' })
      .first(),
  ).toBeVisible();

  // The proposal is decided by a person, in the one approval queue — here declined, so no platform is touched.
  await page.goto('/approvals');
  await decline(page, proposal);

  await api(page, 'post', '/api/automation/rules/bulk', {
    ids: [scaleId, trimId],
    operation: 'archive',
  });
});

test('judges ad groups and ads on their own figures, offering only what each level has', async ({
  page,
}) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/automation');

  // Templates come by level; an ad template opens the builder at the ad level.
  const ads = page.getByRole('heading', { level: 3, name: 'Ads' });
  await expect(ads).toBeVisible(SLOW);
  await page.getByRole('button', { name: 'Use template' }).nth(-2).click();
  const drawer = page.getByRole('dialog');
  await expect(drawer.getByRole('button', { name: 'Ads', exact: true })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  // An ad has no budget: no budget action, no budget-used figure.
  await expect(drawer.getByRole('button', { name: /^Budget/ })).toHaveCount(0);
  await expect(
    drawer.getByRole('combobox', { name: 'Metric' }).first().locator('option[value="budget_used"]'),
  ).toHaveCount(0);

  await drawer.getByRole('button', { name: 'Test now' }).click();
  const result = drawer.getByRole('region', { name: 'Test now' });
  await expect(result.getByText('Test only — nothing was changed or proposed.')).toBeVisible(SLOW);
  await expect(result.getByText(/ads in \d+ ad groups across \d+ campaigns/)).toBeVisible();

  // Switching to ad groups brings budgets back.
  await drawer.getByRole('button', { name: 'Ad groups', exact: true }).click();
  await expect(drawer.getByRole('button', { name: /^Budget/ }).first()).toBeVisible();
});

test('shows, per platform and level, what this product may change and how far it is proven', async ({
  page,
}) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/integrations');

  const matrix = page.getByRole('table', { name: 'What each platform lets this product change' });
  await expect(matrix).toBeVisible(SLOW);
  await expect(matrix.getByText('Sandbox verified').first()).toBeVisible();
  await expect(matrix.getByText('Documented · not wired').first()).toBeVisible();
  await expect(matrix.getByText('Live write verified')).toHaveCount(0);
});
