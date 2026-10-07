import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

async function openIntake(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/client-requests');
}

test.describe('client requests', () => {
  test('publishes who the queue is waiting on, both ways', async ({ page }) => {
    await openIntake(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Client requests' })).toBeVisible({
      timeout: 30_000,
    });

    const summary = page.getByRole('region', { name: 'Intake summary' });

    /*
     * The pair this module exists for. The demo deliberately leaves one request waiting on the client and one
     * unanswered, so neither figure is zero and the distinction is on screen.
     */
    await expect(summary.getByText('Waiting on us')).toBeVisible();
    await expect(summary.getByText('Waiting on the client')).toBeVisible();
    await expect(summary.getByText('Accepted, no date promised')).toBeVisible();
  });

  test('says a client has been ignored rather than that the queue is busy', async ({ page }) => {
    await openIntake(page);

    /*
     * The demo backdates one client-raised request five days with no answer, against a stated patience of two.
     * That is not a busy queue, and the sentence says so.
     */
    await expect(page.getByText(/it is somebody who has not heard back/)).toBeVisible({
      timeout: 30_000,
    });
  });

  test("labels a stated urgency as the client's word, not as a priority", async ({ page }) => {
    await openIntake(page);

    const board = page.getByRole('region', { name: 'The intake queue' });

    await expect(board.getByText(/^Client said: /).first()).toBeVisible({ timeout: 30_000 });
  });

  test('shows both sides of a conversation to the agency', async ({ page }) => {
    await openIntake(page);

    // The demo's creative request carries one client-visible message and one internal note.
    await page.getByRole('button', { name: /Three new story creatives for the Eid push/ }).click();

    const thread = page.getByRole('list', { name: 'Messages on this request' });

    await expect(thread).toBeVisible({ timeout: 30_000 });
    await expect(thread.getByText('Internal only')).toBeVisible();
    await expect(thread.getByText('The client sees this')).toBeVisible();
    await expect(thread.getByText(/Reuse the Ramadan masters/)).toBeVisible();
  });

  test('asks for the reason the client will read before declining', async ({ page }) => {
    await openIntake(page);

    await page.getByRole('button', { name: /Can we pause the retargeting over Eid\?/ }).click();

    const moves = page.getByRole('region', { name: 'Where it can go' });
    await expect(moves).toBeVisible({ timeout: 30_000 });
    await moves.getByRole('button', { name: 'Declined' }).click();

    await expect(page.getByLabel(/Why it is being declined/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save and move' })).toBeDisabled();
  });

  test('will not send a reply until the audience has been chosen', async ({ page }) => {
    await openIntake(page);

    await page.getByRole('button', { name: /Can we pause the retargeting over Eid\?/ }).click();

    await page.getByLabel('Reply', { exact: true }).fill('Looking at it now.');

    /*
     * No default anywhere. An internal note reaching a client ends a relationship, and the button says what it
     * would do rather than doing something unstated.
     */
    await expect(page.getByRole('button', { name: 'Choose who sees this first' })).toBeDisabled();

    await page.getByRole('button', { name: 'The client will see this' }).click();
    await expect(page.getByRole('button', { name: 'Send to the client' })).toBeEnabled();
  });

  test('raises a task against a request and leaves the request a request', async ({ page }) => {
    /*
     * Named per project and per retry. Both Playwright projects run against **one** seeded database, so a fixed
     * title is created twice and a strict locator then resolves to two buttons — a failure that looks like a
     * product defect and is a shared-fixture collision. The suite's sign-in helpers take the same precaution.
     */
    const title = `Export the Eid masters (${test.info().project.name} ${test.info().retry})`;

    await openIntake(page);

    await page.getByRole('button', { name: /Three new story creatives for the Eid push/ }).click();

    const work = page.getByRole('region', { name: 'The work on it' });
    await expect(work).toBeVisible({ timeout: 30_000 });

    await work.getByLabel('Raise a task for this').fill(title);
    await work.getByRole('button', { name: 'Add to the board' }).click();

    // The request keeps its own state; the task is linked to it.
    await expect(work.getByText(title)).toBeVisible();
    await expect(page.getByText('Accepted').first()).toBeVisible();

    // And it is on the task board, about this request.
    await page.goto('/tasks');
    await expect(page.getByRole('button', { name: title })).toBeVisible({ timeout: 30_000 });
  });

  test('offers no task for a question, and says why', async ({ page }) => {
    await openIntake(page);

    await page
      .getByRole('button', { name: /Is the Riyadh audience still the biggest one\?/ })
      .click();

    await expect(page.getByText(/A question needs an answer rather than a task/)).toBeVisible({
      timeout: 30_000,
    });
  });

  test('shows a client only their own side, and only their own requests', async ({ page }) => {
    await openIntake(page, ACCOUNTS.client);

    await expect(page.getByRole('heading', { level: 1, name: 'Your requests' })).toBeVisible({
      timeout: 30_000,
    });

    /*
     * The most important assertion in this file. The agency's internal note on the Eid creative is excluded in
     * SQL for a reader without `client_requests.view`, so it is not on the page in any form — and neither are the
     * agency's own figures, its plan of work, or the triage controls.
     */
    await expect(page.getByText(/Reuse the Ramadan masters/)).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Intake summary' })).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Where it can go' })).toHaveCount(0);
    await expect(page.getByText('Who sees this')).toHaveCount(0);

    /*
     * Their own unanswered request is there — and deliberately has no thread, because nobody has replied to it.
     * The request that *does* carry a reply is the Eid creative, so the thread is asserted on that one: it proves
     * the client sees the client-visible message and, from the assertions above, not the internal note beside it.
     */
    await expect(
      page.getByRole('button', { name: /Can we pause the retargeting over Eid\?/ }),
    ).toBeVisible();

    await page.getByRole('button', { name: /Three new story creatives for the Eid push/ }).click();

    const thread = page.getByRole('list', { name: 'Messages on this request' });

    await expect(thread).toBeVisible();
    await expect(thread.getByText(/Booked in for the 15th/)).toBeVisible();
  });

  test('lets a client ask for something, and says their urgency reorders nothing', async ({
    page,
  }) => {
    await openIntake(page, ACCOUNTS.client);

    await page.getByRole('button', { name: 'Ask us for something' }).click();

    await expect(page.getByRole('heading', { name: 'New request' })).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByText(/it does not reorder anybody's work on its own/)).toBeVisible();

    await page.getByLabel('What do you need').fill('Add the new branch to the Eid campaign');
    await page.getByRole('button', { name: 'Send the request' }).click();

    await expect(
      page.getByRole('button', { name: /Add the new branch to the Eid campaign/ }),
    ).toBeVisible({ timeout: 30_000 });
  });

  test('refuses the queue to a role without the permission', async ({ page }) => {
    /*
     * A media buyer reads the intake queue but cannot triage it; a creative holds neither view nor submit, so the
     * surface says so outright. Asserted on the creative, because "refused" is the state worth testing.
     */
    await openIntake(page, ACCOUNTS.creative);

    await expect(page.getByText('Requests are not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openIntake(page);

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'طلبات العملاء' })).toBeVisible({
      timeout: 30_000,
    });
  });
});
