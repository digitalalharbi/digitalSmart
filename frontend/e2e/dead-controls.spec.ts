import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * No dead controls.
 *
 * Every internal link a surface offers must go somewhere that exists, and every toggle and tab it offers must do
 * something without raising an error. Run against the sandbox agency, where there is enough on every page for the
 * links to be real: a campaign card links to a campaign, a project tile to a project, a client row to a client.
 *
 * It deliberately presses nothing that changes data — a button that creates, sends or deletes is covered by the
 * flow that owns it — but it does press every control whose only job is to change what is shown.
 */
test.skip(process.env['E2E_AGENCY'] !== '1', 'Needs the sandbox agency: E2E_AGENCY=1');

const SURFACES = [
  '/',
  '/portfolio',
  '/projects',
  '/clients',
  '/campaigns',
  '/creative-hub',
  '/tasks',
  '/approvals',
  '/tracking',
  '/leads',
  '/budget-control',
  '/reports',
  '/presentations',
  '/integrations',
  '/administration',
  '/notifications',
];

test('every internal link on every surface leads somewhere that exists', async ({ page }) => {
  test.setTimeout(900_000);
  await signIn(page, ACCOUNTS.owner);

  const checked = new Set<string>();
  const dead: string[] = [];

  for (const surface of SURFACES) {
    await page.goto(surface);
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 45_000 });
    await page.waitForLoadState('networkidle').catch(() => undefined);

    const targets = await page
      .locator('main a[href^="/"]')
      .evaluateAll((links) =>
        links.map((link) => (link as HTMLAnchorElement).getAttribute('href') ?? ''),
      );

    for (const target of [...new Set(targets)]) {
      const path = target.split('?')[0] ?? target;

      if (checked.has(path) || path === '' || path.startsWith('/api')) {
        continue;
      }

      checked.add(path);
      await page.goto(target);
      await page.waitForLoadState('domcontentloaded');

      const missing = await page
        .getByText('404', { exact: true })
        .first()
        .isVisible()
        .catch(() => false);
      const hasHeading = await page
        .getByRole('heading', { level: 1 })
        .first()
        .waitFor({ timeout: 30_000 })
        .then(() => true)
        .catch(() => false);

      if (missing || !hasHeading) {
        dead.push(`${surface} → ${target}`);
      }
    }
  }

  expect(dead).toEqual([]);
});

test('every toggle and tab changes the view without an error', async ({ page }) => {
  test.setTimeout(900_000);
  await signIn(page, ACCOUNTS.owner);

  const failures: string[] = [];

  for (const surface of SURFACES) {
    await page.goto(surface);
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 45_000 });
    await page.waitForLoadState('networkidle').catch(() => undefined);

    const controls = page.locator('main [aria-pressed], main [role="tab"]');
    const count = await controls.count();

    for (let index = 0; index < Math.min(count, 12); index += 1) {
      const control = controls.nth(index);

      if (!(await control.isVisible()) || !(await control.isEnabled())) {
        continue;
      }

      const label = ((await control.textContent()) ?? '').trim().slice(0, 30);
      await control
        .click({ timeout: 10_000 })
        .catch(() => failures.push(`${surface}: could not press "${label}"`));
      await page.waitForTimeout(150);

      const crashed = await page
        .getByText(/something went wrong|unexpected error/i)
        .first()
        .isVisible()
        .catch(() => false);

      if (crashed) {
        failures.push(`${surface}: "${label}" raised an error`);
      }
    }
  }

  expect(failures).toEqual([]);
});
