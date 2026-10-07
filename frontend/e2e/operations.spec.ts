import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

async function openBoard(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/tasks');
}

test.describe('the operations board', () => {
  test('shows every column, including the empty ones', async ({ page }) => {
    await openBoard(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Operations' })).toBeVisible({
      timeout: 30_000,
    });

    const board = page.getByRole('region', { name: 'The board' });
    await expect(board).toBeVisible();

    // A board whose columns appear and disappear with the work is one nobody can learn the shape of.
    for (const column of ['To do', 'In progress', 'Blocked', 'In review', 'Done', 'Cancelled']) {
      await expect(
        board.getByRole('heading', { level: 2, name: new RegExp(column) }),
      ).toBeVisible();
    }
  });

  test('publishes the undated count beside the overdue one', async ({ page }) => {
    await openBoard(page);

    const summary = page.getByRole('region', { name: 'Board summary' });

    /*
     * The pairing this module exists for. The demo board deliberately carries two undated tasks, so the
     * figure that says "this much work cannot be late" is non-zero and its badge is on screen.
     */
    await expect(summary.getByText('Overdue', { exact: true })).toBeVisible({ timeout: 30_000 });
    await expect(summary.getByText('No due date', { exact: true })).toBeVisible();
    await expect(summary.getByText('Cannot be late')).toBeVisible();
  });

  test('refuses to read the overdue figure as lateness when most work is undated', async ({
    page,
  }) => {
    await openBoard(page);

    /*
     * The demo's open board is small and two of its tasks have no date, so the dated share falls below the
     * threshold and the sentence appears. It is an alert above the chart rather than a footnote inside it,
     * because a reader quoting the overdue figure as the month's lateness has already gone wrong.
     */
    await expect(
      page.getByText(/Read the overdue figure as a statement about the dated work/),
    ).toBeVisible({ timeout: 30_000 });
  });

  test('draws undated work as its own bucket, not as "later"', async ({ page }) => {
    await openBoard(page);

    const frame = page.getByRole('region', { name: 'When the open work is due' });
    await expect(frame).toBeVisible({ timeout: 30_000 });
    await frame.getByRole('button', { name: 'Table' }).click();

    await expect(frame.getByRole('row', { name: /No due date/ })).toBeVisible();
    await expect(frame.getByRole('row', { name: /Later/ })).toBeVisible();
  });

  test('counts the unassigned pile as a row of the workload', async ({ page }) => {
    await openBoard(page);

    const frame = page.getByRole('region', { name: 'Who is carrying the work' });
    await expect(frame).toBeVisible({ timeout: 30_000 });
    await frame.getByRole('button', { name: 'Table' }).click();

    // A chart of named people only shows a comfortable team beside work nobody has agreed to do.
    await expect(frame.getByRole('row', { name: /Nobody/ })).toBeVisible();
  });

  test('keeps showing why work was blocked, and offers only the legal moves', async ({ page }) => {
    await openBoard(page);

    // The demo's pixel task is blocked, with its reason.
    await page.getByRole('button', { name: /Get the pixel onto the checkout page/ }).click();

    await expect(page.getByText(/Waiting on the client's developer/)).toBeVisible({
      timeout: 30_000,
    });

    const moves = page.getByRole('region', { name: 'Where it can go' });

    /*
     * Blocked work goes back into progress or is cancelled. "Done" is absent rather than present-and-refused:
     * offering a move the server rejects teaches people to distrust the interface.
     */
    await expect(moves.getByRole('button', { name: 'In progress' })).toBeVisible();
    await expect(moves.getByRole('button', { name: 'Done' })).toHaveCount(0);
  });

  test('asks for a reason before cancelling', async ({ page }) => {
    await openBoard(page);

    await page.getByRole('button', { name: /Audit the UTM naming/ }).click();

    const moves = page.getByRole('region', { name: 'Where it can go' });
    await moves.getByRole('button', { name: 'Cancelled' }).click();

    // Asked before the request, not after a refusal. The server and a CHECK refuse it too.
    await expect(page.getByLabel(/Why it is being cancelled/)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('button', { name: 'Save and move' })).toBeDisabled();
  });

  test('refuses the board to a client viewer', async ({ page }) => {
    await openBoard(page, ACCOUNTS.client);

    /*
     * An agency's board carries its own reasons for work being blocked. The permission is withheld rather
     * than the rows filtered, so the surface says so outright.
     */
    await expect(page.getByText('The operations board is not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });
});
