import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Emailing a client-safe report to somebody outside the agency, and the recipient's own "stop" page.
 *
 * What is asserted is what a person adding a client's address must be told: that this installation cannot send email
 * yet, that a consent sentence is required, and that nothing has been sent. No test here sends anything — the suite
 * has no mail provider, which is exactly the state the screen has to be honest about.
 */
function unique(local: string): string {
  const project = test
    .info()
    .project.name.replace(/[^a-z0-9]/gi, '')
    .toLowerCase();

  return `${local}-${project}-${String(test.info().retry)}@customer.example`;
}

const CLIENT_SAFE = 'Monthly performance';

test.describe('report email recipients', () => {
  test('records a recipient with consent, says email is not sent yet, and removes them', async ({
    page,
  }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/reports');

    await page
      .getByRole('button', { name: new RegExp(`${CLIENT_SAFE}(?! —)`) })
      .first()
      .click();
    await page.getByRole('link', { name: 'Manage', exact: true }).click();
    await page.getByRole('tab', { name: 'Sharing' }).click();

    await expect(page.getByRole('heading', { name: 'Email this report to a client' })).toBeVisible({
      timeout: 30_000,
    });

    /* Before anybody is listed: a recipient over a server with no mail provider is receiving nothing. */
    await expect(page.getByText(/Email is not sent from this installation yet/)).toBeVisible({
      timeout: 30_000,
    });

    const add = page.getByRole('button', { name: 'Add recipient' });
    const address = unique('noura');

    /* An address alone is not enough: how they agreed is required. */
    await page.getByLabel(/Recipient's email/).fill(address);
    await expect(add).toBeDisabled();

    await page
      .getByLabel(/How did they agree/)
      .fill('Asked for the weekly link in the kickoff call on 3 November.');
    await add.click();

    const row = page.locator('li', { hasText: address });
    await expect(row).toBeVisible({ timeout: 30_000 });
    await expect(row.getByText('Active')).toBeVisible();
    await expect(row.getByText(/Consent: Asked for the weekly link/)).toBeVisible();
    await expect(row.getByText(/Nothing has been sent yet/)).toBeVisible();

    /* The same address twice is refused, in the server's own words. */
    await page.getByLabel(/Recipient's email/).fill(address);
    await page.getByLabel(/How did they agree/).fill('Asked again in the same call, to be sure.');
    await add.click();
    await expect(page.getByText('That address already receives this report.')).toBeVisible({
      timeout: 30_000,
    });

    await row.getByRole('button', { name: `Remove ${address}` }).click();
    await expect(row).toHaveCount(0, { timeout: 30_000 });
  });

  test('gives a recipient a stop page that needs no account and does nothing until pressed', async ({
    browser,
    baseURL,
  }) => {
    /* No cookies at all: the person holding this link is a client, not a member. */
    const visitor = await browser.newContext(baseURL === undefined ? {} : { baseURL });
    const page = await visitor.newPage();

    try {
      await page.goto(`/report-emails/unsubscribe/${'a'.repeat(64)}`);

      await expect(
        page.getByRole('heading', { level: 1, name: 'Stop these report emails' }),
      ).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole('navigation')).toHaveCount(0);

      await page.getByRole('button', { name: 'Stop the emails' }).click();

      /* The same answer for a token nobody issued as for a real one. */
      await expect(page.getByRole('status')).toContainText('You will not be emailed this report', {
        timeout: 30_000,
      });
    } finally {
      await visitor.close();
    }
  });
});
