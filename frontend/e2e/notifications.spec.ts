import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

async function openCentre(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/notifications');
}

test.describe('notifications', () => {
  test('says what would put something here when nothing has', async ({ page }) => {
    /*
     * The demo seeds its approvals as the owner, and nobody is told what they did themselves — so the owner's
     * centre is genuinely empty, which is the state worth testing. An empty notification centre is usually a
     * quiet week rather than a broken feature, and the page names the four things that would fill it.
     */
    await openCentre(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Notifications' })).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByText('Nothing yet')).toBeVisible();
    await expect(page.getByText(/when a task is assigned to you/)).toBeVisible();
  });

  test('says live updates are off rather than implying it is live', async ({ page }) => {
    await openCentre(page);

    /*
     * "Live" needs both sides: a server that can push and a browser with a key to subscribe with. The
     * end-to-end stack has neither a Reverb server nor a frontend key, so the page says it is polling.
     *
     * This assertion caught the real version of that bug: the backend's `.env` names a broadcast connection, so
     * an earlier `is_live` that trusted the connection name alone reported live while nothing was subscribed.
     */
    await expect(page.getByText(/Live updates are off for this session/)).toBeVisible({
      timeout: 30_000,
    });
  });

  test('shows a bell with nothing waiting, and no badge', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/');

    /*
     * A dot that is always there is a dot nobody reads, so an empty bell carries nothing. The accessible name is
     * what says which number it would be carrying.
     */
    await expect(
      page.getByRole('button', { name: 'Notifications. Nothing waiting on you.' }),
    ).toBeVisible({ timeout: 30_000 });
  });

  test('tells the creative about the work assigned to them, and counts it as waiting', async ({
    page,
  }) => {
    /*
     * The demo assigns a task to the creative through the real use case, so there is a notification waiting on
     * them — and the bell carries it as "waiting on you" rather than as an unread count, which is the whole
     * argument for that badge.
     *
     * The creative is also the one demo account on a project, which is what makes the task visible to them at
     * all. See the note in the demo seeder on why the buyer is deliberately left unassigned.
     */
    await signIn(page, ACCOUNTS.creative);
    await page.goto('/notifications');

    await expect(page.getByRole('heading', { level: 1, name: 'Notifications' })).toBeVisible({
      timeout: 30_000,
    });

    const summary = page.getByRole('region', { name: 'Notification summary' });
    await expect(summary.getByText('Needs you')).toBeVisible();

    const list = page.getByRole('list', { name: 'Your notifications' });
    await expect(list.getByText('Waiting on you').first()).toBeVisible();
    await expect(list.getByText(/Second pass on the story copy/)).toBeVisible();
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openCentre(page);

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'الإشعارات' })).toBeVisible({
      timeout: 30_000,
    });
  });
});
