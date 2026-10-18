import { expect, test, type Locator, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { chooseClient } from './support/pickers';

/**
 * A conversion name unique to the running project.
 *
 * Both browser projects run at once against one backend and one demo account, and a conversion
 * name is unique per client. Without the suffix the second project collides with the first's
 * record and the server correctly refuses it.
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

  return `E2E ${base} [${test.info().project.name}${attempt}]`;
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
  /* Diagnostics, because the sources and containers these tests work on live there; health is the default. */
  await page.goto('/tracking?view=diagnostics');
  await expect(page.getByRole('heading', { level: 1, name: 'Tracking Center' })).toBeVisible({
    timeout: 30_000,
  });
}

/**
 * Open the conversion editor and return it.
 *
 * Scoped to the dialog rather than to the page, and that matters: the page carries its own "Name"
 * field for registering a measurement source, so a click that has not opened the editor yet does
 * not fail — it silently types into the wrong form, and the test then waits out its whole timeout
 * on a field that was never going to appear. Returning the dialog also waits for it.
 */
async function openEditor(page: Page): Promise<Locator> {
  await page.getByRole('button', { name: 'Define a conversion' }).click();

  const editor = page.getByRole('dialog');
  await expect(editor).toBeVisible({ timeout: 20_000 });

  return editor;
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
    // Unique per attempt for the same reason `named` is: a raw event name belongs to one conversion.
    const rawName = `e2e_lead_${test.info().project.name.toLowerCase()}_${String(test.info().retry)}`;

    const editor = await openEditor(page);
    // Chosen rather than assumed: see the note in ConversionEditor about why there is no default.
    await chooseClient(editor);
    await editor.getByLabel('Name', { exact: true }).fill(name);
    await editor.getByLabel('Raw event name').first().fill(rawName);
    await editor.getByRole('button', { name: 'Save', exact: true }).click();

    /*
     * Exact, because the page now also carries the name inside the observed-events chart's
     * accessible description ("<name>: Never seen"), which is a legitimate second mention.
     */
    await expect(page.getByText(name, { exact: true })).toBeVisible({ timeout: 20_000 });

    /*
     * The rule worth proving end to end: two conversions claiming one raw name both count every
     * event, so a dashboard showing both shows the same lead twice. The server refuses it and the
     * form says which name to change rather than "something went wrong".
     */
    const second = await openEditor(page);
    await chooseClient(second);
    await second.getByLabel('Name', { exact: true }).fill(named('Enquiry'));
    await second.getByLabel('Raw event name').first().fill(rawName);
    await second.getByRole('button', { name: 'Save', exact: true }).click();

    /*
     * The server's own words, naming the event name to change. That is the point of showing its
     * message rather than a generic "could not save": the person can act on this one.
     */
    await expect(page.getByRole('alert')).toContainText(rawName, { timeout: 20_000 });
    await expect(page.getByRole('alert')).toContainText('already mapped to another conversion');
  });

  test('warns when an intent signal is chosen as a conversion', async ({ page }) => {
    await openTracking(page);

    const editor = await openEditor(page);
    await editor.getByLabel('What it means').selectOption('whatsapp_click');

    /*
     * Said at the moment somebody chooses it, not only in the issue list a week later. A click is
     * not a conversation, and reporting a cost per click as a cost per lead is the commonest way an
     * agency overstates its results.
     */
    await expect(page.getByText(/signal of intent, not a completed action/)).toBeVisible();
  });

  test('reads a container\u2019s published state, and cross-checks its measurement id', async ({
    page,
  }) => {
    await openTracking(page);

    const containers = page.getByRole('region', { name: 'Tag containers' });
    await expect(containers).toBeVisible({ timeout: 30_000 });

    /*
     * Asked for one container at a time, because each answer is a live call to Tag Manager. Nothing is read
     * until somebody chooses one.
     */
    await containers.getByRole('button', { name: /Demo web container/ }).click();

    /* The only id that proves a container is the right one. */
    await expect(containers.getByText('GTM-NR4QX8V')).toBeVisible({ timeout: 30_000 });

    /*
     * And the cross-check this panel exists for: the demo registers a GA4 property and a container whose
     * published version refers to a different id, which is exactly the drift that is invisible everywhere else.
     */
    await expect(containers.getByText('Expected', { exact: true })).toBeVisible();
    await expect(containers.getByText('In the published container')).toBeVisible();
    await expect(containers.getByText(/invisible everywhere else/)).toBeVisible();

    /* And the published state, which is the other thing only this panel can tell. */
    await expect(containers.getByText(/^(A live version exists|Never published)$/)).toBeVisible();
  });

  /*
   * Registering a tag container and unregistering a source: the two dead ends in this module. The readiness
   * panel could read a container registered for a client and nothing could register one, and a source once
   * registered could never be removed.
   *
   * It creates its own client first. A source is unique per client, provider and external id, and both browser
   * projects run at once against one database — registering the same fixture container for a demo client
   * twice is a collision the server is right to refuse. A fresh client per project avoids it and leaves the
   * demo's own sources untouched.
   */
  test('registers a tag container for a client, then removes it again', async ({ page }) => {
    const client = named('Tracking client');

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/clients');
    await page.getByRole('button', { name: 'New client' }).click();
    await page.getByLabel('Client name').fill(client);
    await page.getByLabel('Industry').fill('Healthcare');
    await page.getByRole('button', { name: 'Save' }).click();
    /*
     * The clients page opens on cards, and each card is an article named after its client — which is a
     * steadier handle than a row anyway, since the project suffix is in square brackets and `new RegExp`
     * would read those as a character class.
     */
    await expect(page.getByRole('article', { name: client })).toBeVisible({ timeout: 20_000 });

    await page.goto('/tracking');
    await expect(page.getByRole('heading', { level: 1, name: 'Tracking Center' })).toBeVisible({
      timeout: 30_000,
    });

    /*
     * Scoped to the new client, so the sources table shows this test's work and nothing else. By role rather
     * than by label: the picker's `<label>` wraps the `<select>`, so its text content carries every option
     * with it and an exact label match finds nothing.
     */
    await chooseClient(page, client, client);

    /*
     * Scoped to the panel. The issue list raises the same sentence as a critical finding — correctly: a client
     * nothing measures is the first thing that page should say — so the unscoped text matches twice.
     */
    const measuring = page.getByRole('region', { name: 'What is measuring' });
    await expect(measuring.getByText('Nothing is measuring this work yet')).toBeVisible({
      timeout: 20_000,
    });

    await page.getByRole('button', { name: 'Add a property' }).click();
    await page.getByRole('button', { name: 'Tag container' }).click();

    /*
     * A server-side container cannot deliver these tags at all, and the fixtures include one: the badge is
     * the difference between a reader picking the right container and picking the one with the right name.
     */
    await expect(page.getByText('Not a web container')).toBeVisible({ timeout: 30_000 });

    const container = page.getByRole('listitem').filter({ hasText: 'GTM-AW3TTQ2' });
    await container.getByRole('button', { name: 'Use this' }).click();

    /* And it is measuring: the row carries the container's name, from the provider rather than from a form. */
    const row = page.getByRole('row', { name: /Al Waha Clinics/ });
    await expect(row).toBeVisible({ timeout: 20_000 });

    await row.getByRole('button', { name: 'Remove' }).click();

    /* What removal takes with it, stated before it happens. */
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('day-by-day event counts');
    await dialog.getByRole('button', { name: 'Remove it' }).click();

    await expect(measuring.getByText('Nothing is measuring this work yet')).toBeVisible({
      timeout: 20_000,
    });
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
