import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

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
    await expect(page.getByText(/the oldest approved 9 days ago/)).toBeVisible();
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
    await expect(page.getByText(/does not start or stop campaigns on the platforms/)).toBeVisible({
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

  test('refuses the queue to a client viewer', async ({ page }) => {
    await openQueue(page, ACCOUNTS.client);

    await expect(page.getByText('Approvals are not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });
});
