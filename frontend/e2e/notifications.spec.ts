import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

async function openCentre(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/notifications');
}

test.describe('notifications', () => {
  test('says what would put something here when nothing has', async ({ page }) => {
    /*
     * The media buyer, who is on no project in the demo. They hold the permissions that would be told about a
     * client request, and the recipient resolver still excludes them — because a notification is a disclosure and
     * they can see none of these clients. So their centre is genuinely empty, which is the state worth testing.
     *
     * The owner is no longer the right account for this: a client now raises a request in the demo, and the owner
     * is correctly told about it.
     */
    await openCentre(page, ACCOUNTS.buyer);

    await expect(page.getByRole('heading', { level: 1, name: 'Notifications' })).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByText('Nothing yet')).toBeVisible();

    /*
     * The list of what can arrive is the server's vocabulary, not a sentence kept in step by hand — the page
     * used to name four things while the server announced ten, lead assignments among them. Open by default
     * when the feed is empty, which is exactly when a reader needs it.
     */
    await expect(page.getByText('A lead is waiting for you')).toBeVisible();
    await expect(page.getByText(/role already lets them see the thing it is about/)).toBeVisible();
    /* And never the permission slug behind a kind: that is operator detail, and a client reaches this page. */
    await expect(page.getByText(/leads\.view/)).toHaveCount(0);
  });

  test('says live updates are off rather than implying it is live', async ({ page }) => {
    await openCentre(page, ACCOUNTS.buyer);

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
    /*
     * The buyer, and not the owner, because this assertion needs an account that cannot be notified in this
     * workspace rather than one that happens not to be.
     *
     * Announcing is a disclosure: the resolver checks the permission, the **visibility** and an active
     * membership. The demo buyer holds the permissions and is deliberately on no project — see the note in the
     * demo seeder — so no task, approval or client request in the workspace is visible to them and nothing can
     * be announced to them.
     *
     * It was the owner, who holds every permission and sees every project, which made this test depend on
     * whether the approvals and client-request specs had run yet; it passed for months and then failed on a run
     * where they went first, reporting two things waiting. It was then the client, until Phase 22 moved a client
     * out of the agency shell — where this bell lives — and into their own portal. The empty state is a real
     * state and deserves a premise that holds under any ordering.
     */
    await signIn(page, ACCOUNTS.buyer);
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

  test('has a tab per category and digest settings, and fits a phone in Arabic', async ({
    page,
  }) => {
    await openCentre(page);
    await expect(page.getByRole('heading', { level: 1, name: 'Notifications' })).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.getByRole('region', { name: 'Notification summary' })).toBeVisible({
      timeout: 30_000,
    });

    const tabs = page.getByRole('group', { name: 'Tab' });
    await expect(tabs.getByRole('button', { name: /^Performance/ })).toBeVisible();
    await expect(tabs.getByRole('button', { name: /^Budget/ })).toBeVisible();
    await expect(tabs.getByRole('button', { name: /^Creative/ })).toBeVisible();
    await tabs.getByRole('button', { name: /^Approvals/ }).click();
    await expect(page).toHaveURL(/notifications/);

    await page.getByText('Digest settings').click();
    await expect(page.getByRole('button', { name: 'Save settings' })).toBeVisible();
    /* Email and WhatsApp are offered and say plainly they are waiting on a credential. Nothing is faked. */
    await expect(page.getByText('Awaiting credentials').first()).toBeVisible();

    await page.setViewportSize({ width: 375, height: 812 });
    await page.evaluate(() => {
      localStorage.setItem(
        'digitalsmart.ui',
        JSON.stringify({ state: { theme: 'light', locale: 'ar' }, version: 1 }),
      );
    });
    await page.reload();
    await expect(page.getByRole('heading', { level: 1, name: 'الإشعارات' })).toBeVisible({
      timeout: 30_000,
    });
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      ),
    ).toBeLessThanOrEqual(0);
  });
});
