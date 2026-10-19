import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * A title unique to the run.
 *
 * Both browser projects share one seeded database and carrying a change out settles the request, so a test that
 * consumed the seeded one would pass under chromium and be refused under mobile-safari. Each run raises its own
 * request through the UI instead, which also exercises the path a real spend change takes.
 */
function unique(label: string): string {
  return `E2E ${label} ${String(Date.now())}`;
}

/** Raise an approval naming a platform campaign, and approve it — the two acts the carry-out needs. */
async function raiseAndApprove(page: Page, title: string): Promise<void> {
  await page.getByRole('button', { name: 'Raise a request' }).click();

  const dialog = page.getByRole('dialog');

  await expect(dialog).toBeVisible({ timeout: 30_000 });
  await dialog.getByLabel(/What kind of change/).selectOption('campaign_pause');
  await dialog.getByLabel(/What the queue will call it/).fill(title);
  await dialog
    .getByLabel(/Why it is wanted/)
    .fill('Cost per purchase has doubled over four days and the audience is saturated.');

  /*
   * The sandbox ad account this demo connects, and an obviously fictional campaign. No live credentials exist
   * here, so the write goes to the sandbox writer and comes back labelled as such.
   */
  const fields: { key: string; value: string }[] = [
    { key: 'provider', value: 'meta' },
    { key: 'external_account_id', value: 'act_sandbox_1001' },
    { key: 'external_campaign_id', value: '23850000000000009' },
  ];

  for (const [index, field] of fields.entries()) {
    if (index > 0) {
      await dialog.getByRole('button', { name: 'Add a field' }).click();
    }

    /* Exact: "Remove this field" is also labelled, and a substring match would reach for the button. */
    await dialog.getByLabel('Field', { exact: true }).nth(index).fill(field.key);
    await dialog.getByLabel('Value', { exact: true }).nth(index).fill(field.value);
  }

  await dialog.getByRole('button', { name: 'Send for approval' }).click();
  await expect(dialog).toBeHidden();

  await page.getByRole('button', { name: new RegExp(title) }).click();
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Approved', { exact: true }).first()).toBeVisible();
}

async function openQueue(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/approvals');
}

test.describe('approvals', () => {
  test('leads with approved work nobody has carried out', async ({ page }) => {
    await openQueue(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Approvals' })).toBeVisible({
      timeout: 30_000,
    });

    const summary = page.getByRole('region', { name: 'Approval summary' });

    /*
     * The figure that justifies the module. The demo deliberately leaves one approved change outstanding and
     * backdates it nine days, because a queue where everything was done the same day shows nothing.
     */
    await expect(summary.getByText('Approved, not yet done')).toBeVisible();
    await expect(summary.getByText('Nothing has changed yet')).toBeVisible();
    // The age is on the short warning; the full sentence is its hint, in the page for anyone who asks.
    await expect(page.getByText(/oldest 9d/)).toBeVisible();
  });

  test('keeps the decision queue and the enactment queue apart', async ({ page }) => {
    await openQueue(page);

    const pending = page.getByRole('region', { name: 'Waiting for a decision' });
    const outstanding = page.getByRole('region', {
      name: 'Approved, waiting to be carried out',
    });

    await expect(pending.getByText('Launch the Eid prospecting campaign')).toBeVisible({
      timeout: 30_000,
    });
    await expect(outstanding.getByText('Raise the Ramadan daily budget')).toBeVisible();
  });

  test('says on a request that approving it will not make the change', async ({ page }) => {
    await openQueue(page);

    await page.getByRole('button', { name: /Launch the Eid prospecting campaign/ }).click();

    /*
     * Before the buttons rather than after the decision. Without it a decider presses Approve believing the
     * campaign is now live, and tells a client so.
     */
    await expect(page.getByText(/starts or stops nothing by itself/)).toBeVisible({
      timeout: 30_000,
    });
  });

  test('shows the whole proposal with the version a decision names', async ({ page }) => {
    await openQueue(page);

    await page.getByRole('button', { name: /Launch the Eid prospecting campaign/ }).click();

    const proposal = page.getByRole('region', { name: 'What is being proposed' });

    await expect(proposal).toBeVisible({ timeout: 30_000 });
    await expect(proposal.getByText('daily_minor')).toBeVisible();
    await expect(proposal.getByText(/^Version /)).toBeVisible();
  });

  test('asks for a reason before declining', async ({ page }) => {
    await openQueue(page);

    await page.getByRole('button', { name: /Count the newsletter sign-up/ }).click();
    await page.getByRole('button', { name: 'Decline' }).click();

    await expect(page.getByLabel(/Why it is being declined/)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('button', { name: 'Save' })).toBeDisabled();
  });

  test('offers a role that may ask the ask and not the decision', async ({ page }) => {
    /*
     * The creative, who is the one demo account assigned to a project — so they can see the work and are
     * refused the decision, which is the pair this test is about. The buyer is deliberately left on no project
     * in the demo, because the other half of the visibility rule is asserted elsewhere.
     */
    await openQueue(page, ACCOUNTS.creative);

    await expect(page.getByRole('heading', { level: 1, name: 'Approvals' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: /Raise the Ramadan daily budget/ }).click();

    /*
     * Somebody who could approve their own change to a client's spend removes the only check on it. They do get
     * to record having made the change, because they are the person who goes and makes it.
     */
    await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'I have made this change' })).toBeVisible();
  });

  test('says why it cannot carry out a request that names no campaign', async ({ page }) => {
    await openQueue(page);

    await page.getByRole('button', { name: /Raise the Ramadan daily budget/ }).click();

    /*
     * The ordinary case, published rather than hidden. Most approvals in this product describe things no API
     * call expresses, and the reason is what sends somebody to the platform — which is the right place.
     */
    await expect(page.getByText(/does not name a platform campaign/)).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByRole('button', { name: 'Carry this out on the platform' })).toHaveCount(
      0,
    );
  });

  test('carries an approved change out on the platform and labels the source', async ({ page }) => {
    const title = unique('Pause');

    await openQueue(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Approvals' })).toBeVisible({
      timeout: 30_000,
    });

    await raiseAndApprove(page, title);

    await page.getByRole('button', { name: 'Carry this out on the platform' }).click();

    /*
     * Two assertions, and the second is the one that matters. A confirmation names the state the provider was
     * read back as — not the state this product asked for — and a write against sandbox credentials says so on
     * the same panel. A simulated pause recorded as a real one is the single failure this feature must not have.
     */
    await expect(page.getByText(/confirmed:/)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/This was a sandbox write/)).toBeVisible();
  });

  test('refuses the queue to a client viewer', async ({ page }) => {
    await openQueue(page, ACCOUNTS.client);

    await expect(page.getByText('Approvals are not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });
});
