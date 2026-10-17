import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { chooseClient } from './support/pickers';

/**
 * Presentations.
 *
 * Reads only, so nothing is named per project or retry: two browser projects asking for the same deck of the same
 * seeded data get the same slides.
 */
async function openPresentations(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/presentations');
}

async function chooseFirstClient(page: Page): Promise<void> {
  await chooseClient(page);
}

test.describe('presentations', () => {
  test('needs a client before it builds anything', async ({ page }) => {
    await openPresentations(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Presentations' })).toBeVisible({
      timeout: 30_000,
    });

    await expect(page.getByText('Pick a client')).toBeVisible();
  });

  test('builds a deck whose figures are already formatted', async ({ page }) => {
    await openPresentations(page);
    /*
     * The weekly deck, because the sandbox fixture covers the last fourteen days. The default monthly deck reads
     * the previous *calendar* month, which holds no sandbox data from the fifteenth onwards — a test that passed
     * for half of every month and failed for the other half.
     */
    await page.getByRole('button', { name: 'Weekly report' }).click();
    await chooseFirstClient(page);

    /* The title slide names the client and the period. */
    await expect(page.getByRole('article').first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByLabel('Slide 1 of', { exact: false }).first()).toBeVisible();

    /*
     * Every provider in this repository is awaiting an authorization it cannot grant itself, so these are
     * sandbox figures — and a slide is photographed and forwarded, so the label is on the slide.
     */
    await expect(page.getByText('Sandbox').first()).toBeVisible();
  });

  test('makes the caveats a slide rather than a footnote', async ({ page }) => {
    await openPresentations(page);
    await chooseFirstClient(page);

    /* A deck is read out loud by somebody who may not have built it. */
    const rail = page.getByRole('list', { name: 'Slides' });
    await rail
      .getByRole('button', { name: /What these figures do not say/ })
      .click({ timeout: 30_000 });
    await expect(page.getByText(/Read out, not skipped/)).toBeVisible();
  });

  test('says the theme is a placeholder until a reference deck exists', async ({ page }) => {
    await openPresentations(page);
    await chooseFirstClient(page);

    /* A placeholder presented as an agency's brand is worse than an obvious placeholder. */
    await expect(page.getByText(/presentation identity has not been provided yet/)).toBeVisible({
      timeout: 30_000,
    });
  });

  test('offers PowerPoint as a real download', async ({ page }) => {
    await openPresentations(page);
    await chooseFirstClient(page);

    const link = page.getByRole('link', { name: /Download PowerPoint/ });
    await expect(link).toBeVisible({ timeout: 30_000 });

    /*
     * Clicked, and the download captured — rather than fetched with the request context, which carries no
     * session and would only ever prove that the endpoint refuses a stranger. "Editable" is the requirement, so
     * the only way to know is to take delivery of the file and look at it.
     */
    const [download] = await Promise.all([page.waitForEvent('download'), link.click()]);

    /* A deck is a file that gets forwarded; a dated filename is how somebody finds it again. */
    expect(download.suggestedFilename()).toMatch(/\.pptx$/);

    const path = await download.path();
    expect(path).toBeTruthy();

    const { readFileSync } = await import('node:fs');
    const head = readFileSync(path).subarray(0, 2).toString();

    /* A PPTX is a zip, and a zip starts "PK". A file PowerPoint cannot open is a deck somebody re-makes. */
    expect(head).toBe('PK');
  });

  test('explains why the PDF comes from the browser', async ({ page }) => {
    await openPresentations(page);
    await chooseFirstClient(page);

    await expect(page.getByRole('button', { name: /Save as PDF/ })).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByText(/a second renderer to disagree with this one/)).toBeVisible();
  });

  test('asks for a budget when the deck is a media plan', async ({ page }) => {
    await openPresentations(page);

    await page.getByRole('button', { name: 'Media plan', exact: true }).click();

    /* A media plan presents an allocation somebody made; a client id would build the wrong document. */
    await expect(page.getByText(/Pick a budget|No budgets yet/).first()).toBeVisible({
      timeout: 30_000,
    });
  });

  test('publishes the deck it will not compose', async ({ page }) => {
    await openPresentations(page);
    await chooseFirstClient(page);

    const deferred = page.getByRole('region', { name: 'Not composed from this data' });
    await expect(deferred).toBeVisible({ timeout: 30_000 });
    await expect(deferred).toContainText('what is missing is the brief');

    await expect(page.getByRole('button', { name: 'Campaign proposal' })).toHaveCount(0);
  });

  test('keeps presentations away from a role without the permission', async ({ page }) => {
    await openPresentations(page, ACCOUNTS.creative);

    await expect(page.getByText('Presentations are not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openPresentations(page, ACCOUNTS.manager);

    await expect(page.getByRole('heading', { level: 1, name: 'Presentations' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'العروض التقديمية' })).toBeVisible();
  });
});
