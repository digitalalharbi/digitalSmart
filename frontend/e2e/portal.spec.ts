import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * The client portal: the agency's control over it, and what a client actually reads.
 *
 * The demo workspace opens one client's portal exposing performance, reports and tracking, and leaves every other
 * client unconfigured — so both states are on the agency's list from the first run.
 *
 * Nothing here changes a grant. Two browser projects share one seeded database, and a test that switched a module
 * off would be switching it off under the other project's feet.
 */
async function openConfiguration(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/client-portal');
}

test.describe('portal configuration', () => {
  test('lists every client, including the ones that can see nothing', async ({ page }) => {
    await openConfiguration(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Client portal' })).toBeVisible({
      timeout: 30_000,
    });

    const clients = page.getByRole('region', { name: 'Clients' });

    /*
     * The question an agency asks before a quarterly review is which clients still *cannot* see anything, and a
     * list built from portal rows could never answer it.
     */
    await expect(clients.getByText('Not set up')).not.toHaveCount(0);
    await expect(clients.getByText('Exposes nothing')).not.toHaveCount(0);
  });

  test('records who exposed each surface and when', async ({ page }) => {
    await openConfiguration(page);

    await page
      .getByRole('button', { name: /Active/ })
      .first()
      .click();

    /* The entry that answers "who let them see that" after the person who did it has left. */
    await expect(page.getByText(/Switched on /).first()).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/Exposed /).first()).toBeVisible();
  });

  test('publishes what it will never expose, with the reason for each', async ({ page }) => {
    await openConfiguration(page);

    await page
      .getByRole('button', { name: /Active/ })
      .first()
      .click();

    const withheld = page.getByRole('region', { name: 'Never exposed' });
    await expect(withheld).toBeVisible({ timeout: 30_000 });

    /*
     * Shown rather than hidden. A screen listing only what can be exposed makes every refusal look like a feature
     * nobody got round to, and that question then gets asked once a quarter forever.
     */
    await expect(withheld).toContainText('allocation thinking');
    await expect(withheld).toContainText('is a model');

    const always = page.getByRole('region', { name: 'Always available' });
    await expect(always).toContainText('entitled to read a conversation');
  });

  test('narrows a buyer to the clients they are on, which here is none', async ({ page }) => {
    await openConfiguration(page, ACCOUNTS.buyer);

    await expect(page.getByRole('heading', { level: 1, name: 'Client portal' })).toBeVisible({
      timeout: 30_000,
    });

    /*
     * The demo buyer is deliberately assigned to no project — see the note in the demo seeder — so a surface
     * narrowed through a client's projects shows them nothing. The permission opens the screen; the assignment
     * decides what is on it.
     *
     * That they may look and not change is asserted where it can be asserted precisely: `PortalPage.test.tsx`
     * renders the page for a reader holding `portal.view` alone, and the backend suite refuses their write.
     */
    const clients = page.getByRole('region', { name: 'Clients' });
    await expect(clients.getByRole('button')).toHaveCount(0);
    await expect(page.getByText('Pick a client')).toBeVisible();
  });

  test('keeps the configuration away from a creative entirely', async ({ page }) => {
    await openConfiguration(page, ACCOUNTS.creative);

    await expect(page.getByText('The client portal is not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });
});

test.describe('a client reading their portal', () => {
  test('lands on their own portal rather than the agency application', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/');

    /*
     * The agency's shell is not their application. No sidebar of surfaces they cannot open, and no overview of a
     * workspace they are not part of.
     */
    await expect(page).toHaveURL(/\/portal$/, { timeout: 30_000 });
    await expect(page.getByRole('navigation', { name: 'Main navigation' })).toHaveCount(0);
  });

  test('reads the figures it was given, labelled with where they came from', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/portal');

    const nav = page.getByRole('navigation', { name: 'Your portal' });
    await expect(nav).toBeVisible({ timeout: 30_000 });

    await nav.getByRole('button', { name: 'Performance' }).click();

    /*
     * Every provider in this repository is awaiting an authorization it cannot grant itself. A client reading
     * "Sandbox" has been told the truth; one reading an unlabelled figure from a fixture has not.
     */
    await expect(page.getByText('Sandbox').first()).toBeVisible({ timeout: 30_000 });

    // And the two sentences that stop a figure being misread.
    await expect(page.getByText(/Compared with .*, the period immediately before/)).toBeVisible();
    await expect(page.getByText(/ends yesterday/)).toBeVisible();
  });

  test('has no navigation to a surface it may not open', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/portal');

    const nav = page.getByRole('navigation', { name: 'Your portal' });
    await expect(nav).toBeVisible({ timeout: 30_000 });

    /*
     * Store revenue is not exposed to the demo client, and the menu has no entry for it. An item that answered
     * 404 would tell a client a surface about them exists and is being kept from them, which is a sentence about
     * the agency's intentions rather than a feature.
     */
    await expect(nav.getByRole('button', { name: 'Store revenue' })).toHaveCount(0);
  });

  test('can always write to its own thread', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/portal');

    await page
      .getByRole('navigation', { name: 'Your portal' })
      .getByRole('button', { name: 'Requests' })
      .click();

    await expect(page.getByText(/cannot switch this off/)).toBeVisible({ timeout: 30_000 });
  });

  test('is offered no way into the agency application', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/portal');

    await expect(page.getByRole('navigation', { name: 'Your portal' })).toBeVisible({
      timeout: 30_000,
    });

    /*
     * No agency navigation, no notification bell — there is nowhere else for a client to go, and a chrome full of
     * controls that refuse them would invite them to try.
     *
     * That the agency's own endpoints *refuse* a client is asserted in the backend suite, where a status code can
     * be asserted precisely: see `ClientPortalTest`, "is refused before being told what it left out".
     */
    await expect(page.getByRole('navigation', { name: 'Main navigation' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /Notifications/ })).toHaveCount(0);
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/portal');

    await expect(page.getByRole('navigation', { name: 'Your portal' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('button', { name: 'الطلبات' })).toBeVisible();
  });
});

test.describe('an agency preview', () => {
  test("reads the client's own page and says that is what it is", async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/portal');

    /*
     * Reading it as the client would is the thing to do before switching a module on — the same argument the
     * reporting module makes about a client-safe report.
     */
    await expect(page.getByText(/It is their page, not yours/)).toBeVisible({ timeout: 30_000 });
  });
});
