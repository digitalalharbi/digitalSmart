import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Reporting, including the one page in this product a visitor with no account can open.
 *
 * Two of these tests share the demo workspace with the other browser project, so anything they create is named
 * after the project and the retry: both projects run against one seeded database, and a fixed label would have
 * each project revoking the other's link.
 */
async function openReports(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/reports');
}

function unique(suffix: string): string {
  return `${suffix} ${test.info().project.name} ${String(test.info().retry)}`;
}

const CLIENT_SAFE = 'Monthly performance';
const INTERNAL = 'Monthly performance — internal';

test.describe('reports', () => {
  test('says of every saved report who it is for', async ({ page }) => {
    await openReports(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Reports' })).toBeVisible({
      timeout: 30_000,
    });

    const saved = page.getByRole('region', { name: 'Saved reports' });

    /*
     * The badge is on the card rather than behind a click. Whether a document is safe to send is the question
     * somebody answers from a list, in a hurry, just before sending it.
     */
    await expect(saved.getByRole('button', { name: new RegExp(INTERNAL) })).toContainText(
      'Internal',
    );
    await expect(
      saved.getByRole('button', { name: new RegExp(`${CLIENT_SAFE}(?! —)`) }).first(),
    ).toContainText('Safe to send');
  });

  test('leads a rendered report with its period, its source and when it was run', async ({
    page,
  }) => {
    await openReports(page);

    await page
      .getByRole('button', { name: new RegExp(`${CLIENT_SAFE}(?! —)`) })
      .first()
      .click();

    await expect(page.getByRole('heading', { level: 2, name: CLIENT_SAFE })).toBeVisible({
      timeout: 30_000,
    });

    /* A figure with no timestamp is a figure somebody quotes next month. */
    await expect(page.getByText(/Figures as at/)).toBeVisible();
    await expect(page.getByText(/recalculated every time it is opened/)).toBeVisible();
  });

  test('refuses to share an internal report, and says why rather than hiding it', async ({
    page,
  }) => {
    await openReports(page);

    await page.getByRole('button', { name: new RegExp(INTERNAL) }).click();

    const links = page.getByRole('region', { name: 'Links out' });
    await expect(links).toBeVisible({ timeout: 30_000 });

    await expect(links).toContainText('marked internal, so it cannot be shared');

    /*
     * Hiding the control produces a support question; the sentence produces an edit. What must not exist is a
     * path to a link on this report.
     */
    await expect(links.getByRole('button', { name: 'Create the link' })).toHaveCount(0);
  });

  test('will not put a metric in a shape whose rows do not add up', async ({ page }) => {
    await openReports(page);

    await page.getByRole('button', { name: 'New report' }).click();

    const metrics = page.getByRole('group', { name: 'Metrics' });
    await expect(metrics).toBeVisible({ timeout: 30_000 });

    // Reach is offered in a scorecard, where it is a legitimate figure.
    await expect(metrics.getByRole('button', { name: 'Reach', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'A ranking', exact: true }).click();

    /*
     * And is gone from a ranking, because the rows of a ranking are summed. Reach does not add up across
     * campaigns — the same person reached twice is one person — so a per-platform reach column would be a
     * number with no meaning rather than a number that is merely wrong.
     */
    await expect(
      page.getByRole('group', { name: 'Metrics' }).getByRole('button', { name: 'Reach' }),
    ).toHaveCount(0);
  });

  test('shows a new link once, and lets a visitor with no account read it', async ({
    page,
    browser,
    baseURL,
  }) => {
    await openReports(page);

    await page
      .getByRole('button', { name: new RegExp(`${CLIENT_SAFE}(?! —)`) })
      .first()
      .click();

    const links = page.getByRole('region', { name: 'Links out' });
    await expect(links).toBeVisible({ timeout: 30_000 });

    const recipient = unique('Visitor');
    await links.getByLabel('Who it is for').fill(recipient);
    await links.getByRole('button', { name: 'Create the link' }).click();

    /* Said plainly, because there is no endpoint that returns the token again. */
    await expect(page.getByText(/only time the link is shown/)).toBeVisible({ timeout: 30_000 });

    const shown = await page.getByText(/\/shared-reports\/[0-9a-f]{96}/).innerText();
    const token = /([0-9a-f]{96})/.exec(shown)?.[1];
    expect(token).toBeDefined();

    /*
     * A separate context with no cookies: the token is the authorisation, and a page that only worked for
     * somebody already signed in would be a share feature that had never been used by a client.
     */
    const visitor = await browser.newContext(baseURL === undefined ? {} : { baseURL });
    const visiting = await visitor.newPage();

    try {
      await visiting.goto(`/shared-reports/${String(token)}`);

      await expect(visiting.getByRole('heading', { name: CLIENT_SAFE })).toBeVisible({
        timeout: 30_000,
      });
      await expect(visiting.getByText(`Prepared for ${recipient}.`)).toBeVisible();

      /* No way out of the page, and no export: a visitor holds a token, not an account. */
      await expect(visiting.getByRole('navigation')).toHaveCount(0);
      await expect(visiting.getByText('Download CSV')).toHaveCount(0);

      /* And nothing the agency wrote for itself. */
      await expect(visiting.getByText(/Internal note/)).toHaveCount(0);
      await expect(visiting.getByText(/SANDBOX note/)).toHaveCount(0);
    } finally {
      await visitor.close();
    }

    // Turning it off needs a reason, which is the record of why a client's link stopped working.
    const row = links.locator('li', { hasText: recipient });
    await row.getByRole('button', { name: 'Turn off' }).click();

    const confirm = row.getByRole('button', { name: 'Turn it off' });
    await expect(confirm).toBeDisabled();

    await row.getByLabel(/Why it is being turned off/).fill('Sent to the wrong address.');
    await confirm.click();

    await expect(row.getByText('Revoked')).toBeVisible({ timeout: 30_000 });
    await expect(row).toContainText('Sent to the wrong address.');
  });

  test('tells a visitor a dead link is dead, never that it was not found', async ({
    browser,
    baseURL,
  }) => {
    const visitor = await browser.newContext(baseURL === undefined ? {} : { baseURL });
    const visiting = await visitor.newPage();

    try {
      /*
       * A token nobody ever issued. It gets the same answer as a revoked one on purpose: telling a visitor the
       * difference tells anybody guessing tokens when they have guessed one.
       */
      await visiting.goto(`/shared-reports/${'f'.repeat(96)}`);

      await expect(visiting.getByText(/has been turned off/)).toBeVisible({ timeout: 30_000 });
      await expect(visiting.getByText(/not found/i)).toHaveCount(0);
    } finally {
      await visitor.close();
    }
  });

  test('lets a buyer build a report and not send one', async ({ page }) => {
    await openReports(page, ACCOUNTS.buyer);

    await expect(page.getByRole('heading', { level: 1, name: 'Reports' })).toBeVisible({
      timeout: 30_000,
    });

    /*
     * This buyer is assigned to none of that client's projects, so the client's reports are not in
     * their list at all — narrowed in SQL, like every other read in this product. The permission
     * opens the screen; the assignment decides what is on it.
     */
    await expect(page.getByRole('button', { name: new RegExp(CLIENT_SAFE) })).toHaveCount(0);

    // An agency-wide report has no client to be narrowed by, so the role can build and read one.
    await page.getByRole('button', { name: 'New report' }).click();

    const name = unique('Agency-wide');
    await page.getByLabel(/^Name/).fill(name);

    /*
     * Marked safe to send, deliberately. An internal report cannot be shared by anybody, so
     * leaving the default would prove the audience rule again rather than the permission.
     */
    await page.getByRole('button', { name: 'Safe to send to the client' }).click();
    await page.getByRole('button', { name: 'Save the report' }).click();

    const links = page.getByRole('region', { name: 'Links out' });
    await expect(links).toBeVisible({ timeout: 30_000 });

    /*
     * So the absence below is about the permission and nothing else: the report is shareable in
     * principle, and this role still cannot share it. A buyer reporting on their own performance
     * to a client, with nobody reading it first, is the one review this module exists to keep.
     */
    await expect(links).not.toContainText('marked internal');
    await expect(links.getByRole('button', { name: 'Create the link' })).toHaveCount(0);

    // Leaves nothing behind: both browser projects run against one seeded database.
    await page.getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByRole('button', { name: new RegExp(name) })).toHaveCount(0);
  });

  test('keeps reports away from a role without the permission', async ({ page }) => {
    await openReports(page, ACCOUNTS.creative);

    await expect(page.getByText('Reports are not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openReports(page, ACCOUNTS.manager);

    await expect(page.getByRole('heading', { level: 1, name: 'Reports' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'التقارير' })).toBeVisible();
  });
});
