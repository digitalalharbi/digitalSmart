import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

async function openCreative(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/creative-hub');
}

test.describe('creative hub', () => {
  test('shows the board with every stage', async ({ page }) => {
    await openCreative(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Creative Hub' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Board' }).click();
    const board = page.getByRole('region', { name: 'The board' });
    await expect(board).toBeVisible();

    // Every column, including the empty ones: a board whose shape changes is one nobody can learn.
    for (const column of ['Draft', 'In production', 'In review', 'Approved']) {
      await expect(
        board.getByRole('heading', { level: 2, name: new RegExp(column) }),
      ).toBeVisible();
    }
  });

  test('says which placements it can check work against, and why the list is short', async ({
    page,
  }) => {
    await openCreative(page);

    const coverage = page.getByText(/do not publish their creative requirements/);

    /*
     * The honest headline of this phase. The placements with no recorded requirement are the ones an agency's
     * work goes out on unchecked, and the reason is a fact about the platforms.
     */
    await expect(coverage).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/snapchat · story_ad_preview_image/)).toBeVisible();
  });

  test('shows a failing check with both numbers', async ({ page }) => {
    await openCreative(page);

    // The demo's end card is deliberately below the recorded minimum width and over the size limit.
    await page.getByRole('button', { name: /App end card/ }).click();

    await expect(page.getByText('Outside spec').first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/at most/).first()).toBeVisible();
  });

  test('says nothing was checked for a placement with no recorded specification', async ({
    page,
  }) => {
    await openCreative(page);

    await page.getByRole('button', { name: /Reels launch film/ }).click();

    /*
     * Presented as this product's gap rather than a problem with the file — a reader who thinks the file failed
     * will go and change a file that was fine.
     */
    await expect(page.getByText(/not a problem with the file/)).toBeVisible({ timeout: 30_000 });
  });

  /*
   * Two controls that had an endpoint and no caller: the board could not be moved and a brief could not be
   * handed to anybody — `creative_requests.owner_id` was written when a request was opened and never again.
   *
   * Read-only up to the change itself: the suite shares one seeded database between two browser projects, and
   * moving a demo brief would move a card the other project's tests are reading.
   */
  test('offers the legal moves and an owner who can open the work', async ({ page }) => {
    await openCreative(page);

    await page.getByRole('button', { name: /App end card/ }).click();

    /* Who is making it, from the server's own list of people who can open this client's work. */
    const owner = page.getByRole('combobox', { name: 'Who is making it' });
    await expect(owner).toBeVisible({ timeout: 30_000 });
    await expect(owner.getByRole('option', { name: 'Nobody yet' })).toHaveCount(1);
    await expect(owner.getByRole('option', { name: 'Demo Creative' })).toHaveCount(1);

    /* And the hint is a description rather than part of the control's name. */
    await expect(owner).toHaveAccessibleDescription(/Only colleagues who can open/);

    /*
     * The moves are the server's own `allowed_next`. "Cancelled" is reachable from every open state, and
     * clicking it asks for the reason before sending anything — the reason being the only record of why the
     * work stopped.
     */
    await page.getByRole('button', { name: 'Cancelled' }).click();
    await expect(page.getByLabel(/Why it is being cancelled/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cancel this work' })).toBeDisabled();
  });

  test('offers no approval to a creative', async ({ page }) => {
    await openCreative(page, ACCOUNTS.creative);

    await expect(page.getByRole('heading', { level: 1, name: 'Creative Hub' })).toBeVisible({
      timeout: 30_000,
    });

    /*
     * A creative uploads all day and signs nothing off. The separation is what makes "approved" mean anything,
     * and the server refuses the request whether or not the button is there.
     */
    await expect(page.getByRole('button', { name: 'Approve this version' })).toHaveCount(0);
  });

  test('keeps the hub away from a client viewer', async ({ page }) => {
    await openCreative(page, ACCOUNTS.client);

    await expect(page.getByText('The Creative Hub is not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openCreative(page, ACCOUNTS.manager);

    await expect(page.getByRole('heading', { level: 1, name: 'Creative Hub' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(
      page.getByRole('heading', { level: 1, name: 'مركز الأعمال الإبداعية' }),
    ).toBeVisible();
  });
});
