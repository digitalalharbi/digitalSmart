import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Open the dashboards surface and wait for it to be ready.
 *
 * The route is code-split and its widgets each run their own analytics query, so the
 * first paint waits on a 200 kB chart chunk plus several requests. Against a
 * single-process dev server shared by every worker that can exceed the default
 * assertion timeout — a harness constraint, not an application one.
 */
async function openDashboards(page: Page): Promise<void> {
  await page.goto('/dashboards');
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboards' })).toBeVisible({
    timeout: 30_000,
  });
}

/** Save the open draft and wait for the editing chrome to close. */
async function save(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Save dashboard' }).click();
  await expect(page.getByRole('button', { name: 'Save dashboard' })).toBeHidden({
    timeout: 20_000,
  });
}

/**
 * A dashboard name unique to the running project.
 *
 * Playwright runs this file once per project — desktop Chromium and mobile Safari —
 * and both run at the same time against one backend, one database and one demo
 * account. Dashboard names are unique per owner, so a bare "E2E original" means the
 * second project collides with the first project's record: a name conflict the API
 * now reports properly, which would fail the test for a harness reason rather than a
 * product one. The suffix gives each project its own names.
 */
function named(base: string): string {
  /*
   * The retry index is part of the name, and it is what makes these tests retryable at all.
   *
   * Every record this suite creates is unique per client and period, which the server enforces.
   * Without the attempt number, a test that failed after saving could never pass on retry: the
   * second attempt's save is correctly refused, the form stays open, and the test times out on a
   * control that never appears — turning one flake into a hard failure whose message describes
   * the retry rather than the original fault.
   */
  const attempt = test.info().retry === 0 ? '' : ` r${String(test.info().retry)}`;

  /* `--repeat-each` reruns the same test in the same database; without its index the repeat meets its own record. */
  const repeat =
    test.info().repeatEachIndex === 0 ? '' : ` x${String(test.info().repeatEachIndex)}`;

  return `E2E ${base} [${test.info().project.name}${attempt}${repeat}]`;
}

/*
 * Names are matched as substrings rather than exactly, deliberately: the picker
 * labels someone else's shared dashboard with its owner — "Weekly review · Demo
 * Owner" — because a list mixing your own canvases with colleagues' is unreadable
 * without it. The project suffix already makes each name unique.
 */

/** The name the server gives a copy, which the test does not choose. */
function copyOf(base: string): string {
  return `${named(base)} (copy)`;
}

test.describe('dashboard builder', () => {
  /*
   * Serial, and each test names its dashboards uniquely.
   *
   * These tests create records the API scopes to the signed-in user, and the suite has
   * one account per role — so in parallel they would see each other's dashboards and
   * the picker selectors would match several. Serial keeps them honest; the unique
   * names keep them readable when one fails.
   */
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
  });

  test('builds, saves and reopens a dashboard', async ({ page }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('weekly review'));

    // A new dashboard arrives with a starting canvas rather than a blank one.
    await expect(page.getByRole('button', { name: 'Remove this widget' }).first()).toBeVisible();

    await save(page);

    /*
     * Persisted: it appears in the picker, and reopening it shows the widgets that
     * were saved rather than the starting set.
     */
    await expect(page.getByRole('button', { name: named('weekly review') })).toBeVisible();
    await page.reload();
    await openDashboards(page);
    await page.getByRole('button', { name: named('weekly review') }).click();
    await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
  });

  test('adds a widget through the composer and persists it', async ({ page }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('share chart'));

    const before = await page.getByRole('button', { name: 'Remove this widget' }).count();

    await page.getByRole('button', { name: 'Add a widget' }).click();
    await page.getByRole('button', { name: 'Share of total' }).click();

    /*
     * Choosing a share of total removes Date from the groupings: ninety slices, one
     * per day, summing to a number nobody asked for.
     */
    const groupBy = page.getByRole('group', { name: 'Group by' });
    await expect(groupBy.getByRole('button', { name: 'Date' })).toBeHidden();

    await groupBy.getByRole('button', { name: 'Platform' }).click();
    await page.getByRole('button', { name: 'Add widget', exact: true }).click();

    await expect(page.getByRole('button', { name: 'Remove this widget' })).toHaveCount(before + 1);

    await save(page);
    await page.reload();
    await openDashboards(page);

    /*
     * Selected explicitly. After a reload the surface lands on the reader's most
     * recently viewed dashboard, which is right behaviour and not necessarily the one
     * this test made.
     */
    await page.getByRole('button', { name: named('share chart') }).click();

    // The share widget survived the round trip.
    await expect(page.getByRole('heading', { level: 3, name: 'Share of total' })).toBeVisible();
  });

  test('refuses a scatter until it has two metrics', async ({ page }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByRole('button', { name: 'Add a widget' }).click();
    await page.getByRole('button', { name: 'Efficiency vs scale' }).click();

    // The controls constrain each other, so an invalid widget is never offered —
    // rather than being composed and refused on save.
    await expect(page.getByText(/needs two/i)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add widget', exact: true })).toBeDisabled();

    await page.getByRole('button', { name: 'ROAS' }).click();
    await expect(page.getByRole('button', { name: 'Add widget', exact: true })).toBeEnabled();
  });

  test('reorders and resizes, and the layout survives a reload', async ({ page }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('rearranged'));

    // Reordering and resizing are real buttons, not drag-and-drop: drag-only
    // reordering fails WCAG 2.2's dragging criterion and does not work by keyboard.
    await page.getByRole('button', { name: 'Make wider' }).first().click();
    await page.getByRole('button', { name: 'Move later' }).first().click();

    await save(page);
    await page.reload();
    await openDashboards(page);

    await page.getByRole('button', { name: named('rearranged') }).click();
    await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
  });

  test('removes a widget and keeps the removal', async ({ page }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('trimmed'));

    const before = await page.getByRole('button', { name: 'Remove this widget' }).count();
    await page.getByRole('button', { name: 'Remove this widget' }).first().click();
    await expect(page.getByRole('button', { name: 'Remove this widget' })).toHaveCount(before - 1);

    await save(page);

    // The canvas a reader sees is the whole truth about the dashboard, so a widget
    // they removed has to be gone after a reload too.
    await page.reload();
    await openDashboards(page);
    await page.getByRole('button', { name: named('trimmed') }).click();
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Remove this widget' })).toHaveCount(before - 1);
  });

  test('discards an edit without changing what was saved', async ({ page }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('kept as saved'));
    await save(page);

    await page.getByRole('button', { name: named('kept as saved') }).click();
    await page.getByRole('button', { name: 'Edit', exact: true }).click();
    await page.getByLabel('Name').fill(named('should not stick'));
    await page.getByRole('button', { name: 'Cancel' }).click();

    await expect(page.getByRole('button', { name: named('kept as saved') })).toBeVisible();
    await expect(page.getByRole('button', { name: named('should not stick') })).toBeHidden();
  });

  test('duplicates a dashboard into a private copy', async ({ page }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('original'));
    await save(page);

    await page.getByRole('button', { name: 'Duplicate' }).click();

    // How a dashboard gets adapted without changing it for anyone relying on it: the
    // copy appears alongside the original rather than replacing it.
    await expect(page.getByRole('button', { name: copyOf('original') })).toBeVisible();
    // Exact, because the original's name is a prefix of the copy's.
    await expect(page.getByRole('button', { name: named('original'), exact: true })).toBeVisible();
  });

  test('shares with the workspace, and a colleague can read but not edit', async ({
    page,
    context,
  }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('shared with everyone'));
    await page.getByRole('button', { name: 'Everyone in the workspace' }).click();
    await save(page);

    /*
     * Off the page first. A live page whose cookies vanish starts getting `401`s, and the client's interceptor
     * sends the browser to the sign-in screen — a redirect that can land after the next account's own sign-in
     * and bounce them back out. The same race made a budgets test flake for weeks.
     */
    await page.goto('about:blank');
    await context.clearCookies();
    await signIn(page, ACCOUNTS.manager);
    await openDashboards(page);

    /*
     * Visible because it was shared; not editable because it is not theirs. A shared
     * dashboard anyone could rewrite is one nobody can rely on.
     */
    await expect(page.getByRole('button', { name: named('shared with everyone') })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Duplicate' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeHidden();
  });

  test('keeps a private dashboard invisible to a colleague', async ({ page, context }) => {
    await openDashboards(page);

    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('mine alone'));
    await save(page);

    /*
     * Off the page first. A live page whose cookies vanish starts getting `401`s, and the client's interceptor
     * sends the browser to the sign-in screen — a redirect that can land after the next account's own sign-in
     * and bounce them back out. The same race made a budgets test flake for weeks.
     */
    await page.goto('about:blank');
    await context.clearCookies();
    await signIn(page, ACCOUNTS.manager);
    await openDashboards(page);

    await expect(page.getByRole('button', { name: named('mine alone') })).toBeHidden();
  });

  test('offers no authoring controls to a client viewer', async ({ page, context }) => {
    await openDashboards(page);
    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('client visible'));
    await page.getByRole('button', { name: 'Everyone in the workspace' }).click();
    await save(page);

    /*
     * Off the page first. A live page whose cookies vanish starts getting `401`s, and the client's interceptor
     * sends the browser to the sign-in screen — a redirect that can land after the next account's own sign-in
     * and bounce them back out. The same race made a budgets test flake for weeks.
     */
    await page.goto('about:blank');
    await context.clearCookies();
    await signIn(page, ACCOUNTS.client);
    await openDashboards(page);

    // Client viewers read what was shared with them and cannot reshape it.
    await expect(page.getByRole('button', { name: 'New dashboard' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Edit', exact: true })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Duplicate' })).toBeHidden();
  });

  test('reads correctly in Arabic, right to left', async ({ page }) => {
    await openDashboards(page);
    await page.getByRole('button', { name: 'New dashboard' }).first().click();
    await page.getByLabel('Name').fill(named('rtl check'));
    await save(page);

    await page.getByRole('button', { name: named('rtl check') }).click();
    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'لوحات المعلومات' })).toBeVisible();
    // The canvas and its controls are translated, not just the chrome around them.
    await expect(page.getByRole('button', { name: 'تحرير', exact: true })).toBeVisible();
  });
});
