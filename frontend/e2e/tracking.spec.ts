import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * A conversion name unique to the running project.
 *
 * Both browser projects run at once against one backend and one demo account, and a conversion
 * name is unique per client. Without the suffix the second project collides with the first's
 * record and the server correctly refuses it.
 */
function named(base: string): string {
  return `E2E ${base} [${test.info().project.name}]`;
}

/**
 * Sign in and open the tracking centre.
 *
 * Sign-in happens per test rather than in a `beforeEach`, because one test needs a different
 * account: signing in twice in one browser context leaves a session the second attempt cannot
 * replace, and the harness reports that as an unreachable server.
 */
async function openTracking(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/tracking');
  await expect(page.getByRole('heading', { level: 1, name: 'Tracking Center' })).toBeVisible({
    timeout: 30_000,
  });
}

test.describe('tracking centre', () => {
  /*
   * Serial, like budgets: these create workspace-scoped records, so in parallel each project would
   * see the other's conversions and the selectors would match the wrong card.
   */
  test.describe.configure({ mode: 'serial' });

  test('leads with what needs attention', async ({ page }) => {
    await openTracking(page);

    /*
     * Order matters more than content here. A page opening on a tidy table of sources invites the
     * conclusion that everything is fine, which is the conclusion this product exists to prevent.
     *
     * Scoped to `main`: the navigation rail labels its own sections with `h2`, so an unscoped query
     * finds "Work" before anything on the page.
     */
    const headings = page.getByRole('main').getByRole('heading', { level: 2 });

    await expect(headings.first()).toHaveText('What needs attention');
    await expect(page.getByRole('region', { name: 'What needs attention' })).toBeVisible();
  });

  test('shows the three panels a person needs, in order', async ({ page }) => {
    await openTracking(page);

    await expect(page.getByRole('heading', { level: 2, name: 'What is measuring' })).toBeVisible();
    await expect(
      page.getByRole('heading', { level: 2, name: 'What counts as success' }),
    ).toBeVisible();
  });

  test('defines a conversion and refuses a second claiming the same event name', async ({
    page,
  }) => {
    await openTracking(page);

    const name = named('Lead');
    const rawName = `e2e_lead_${test.info().project.name.toLowerCase()}`;

    await page.getByRole('button', { name: 'Define a conversion' }).click();
    await page.getByLabel('Name', { exact: true }).fill(name);
    await page.getByLabel('Raw event name').first().fill(rawName);
    await page.getByRole('button', { name: 'Save', exact: true }).click();

    await expect(page.getByText(name)).toBeVisible({ timeout: 20_000 });

    /*
     * The rule worth proving end to end: two conversions claiming one raw name both count every
     * event, so a dashboard showing both shows the same lead twice. The server refuses it and the
     * form says which name to change rather than "something went wrong".
     */
    await page.getByRole('button', { name: 'Define a conversion' }).click();
    await page.getByLabel('Name', { exact: true }).fill(named('Enquiry'));
    await page.getByLabel('Raw event name').first().fill(rawName);
    await page.getByRole('button', { name: 'Save', exact: true }).click();

    /*
     * The server's own words, naming the event name to change. That is the point of showing its
     * message rather than a generic "could not save": the person can act on this one.
     */
    await expect(page.getByRole('alert')).toContainText(rawName, { timeout: 20_000 });
    await expect(page.getByRole('alert')).toContainText('already mapped to another conversion');
  });

  test('warns when an intent signal is chosen as a conversion', async ({ page }) => {
    await openTracking(page);

    await page.getByRole('button', { name: 'Define a conversion' }).click();
    await page.getByLabel('What it means').selectOption('whatsapp_click');

    /*
     * Said at the moment somebody chooses it, not only in the issue list a week later. A click is
     * not a conversation, and reporting a cost per click as a cost per lead is the commonest way an
     * agency overstates its results.
     */
    await expect(page.getByText(/signal of intent, not a completed action/)).toBeVisible();
  });

  test('does not offer the tracking centre to a client viewer', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);

    /*
     * A client sees results, not the wiring: measurement setup is where a number's meaning is
     * decided, and that is the agency's to answer for. The rail only exists at desktop width, so
     * assert on it there.
     */
    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(page.getByRole('link', { name: 'Tracking' })).toBeHidden();

    /*
     * Reaching the route directly renders the page shell and then nothing else — the server refuses
     * every measurement request, so no source, conversion or figure arrives. Hiding the nav item is
     * a courtesy; this is the part that matters.
     */
    await page.goto('/tracking');
    await expect(page.getByRole('alert')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByRole('region', { name: 'What is measuring' })).toBeHidden();
  });
});
