import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

test.describe('administration', () => {
  test('an owner manages people and reads the audit trail', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/administration');

    await expect(page.getByRole('heading', { level: 1, name: 'Administration' })).toBeVisible();
    await expect(page.getByRole('row', { name: /Demo Buyer/ })).toBeVisible();

    await page.getByRole('tab', { name: 'Audit log' }).click();
    // Signing in is itself recorded, so the trail is never empty here.
    await expect(page.getByText('Security').first()).toBeVisible();
  });

  /*
   * Adding a colleague, which nothing could do before: `users.invite` was granted and checked nowhere, and the
   * workspace was closed to anybody new.
   *
   * Run-unique address, because the suite shares one seeded database between two browser projects and this test
   * creates a real account.
   */
  test('adds a colleague and hands over a one-time password', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/administration');

    await expect(page.getByText('Add a colleague')).toBeVisible({ timeout: 30_000 });

    /* No email is sent and the panel says so rather than implying one is on its way. */
    await expect(page.getByText(/No email is sent/)).toBeVisible();

    const address = `colleague-${Date.now()}@example.com`;

    await page.getByLabel('Name*', { exact: true }).fill('Noura Al-Shehri');
    await page.getByLabel(/Email address/).fill(address);
    await page.getByRole('button', { name: 'Add them' }).click();

    /*
     * The password is shown once and the copy says so. Asserted on the sentence rather than the value, which is
     * random by design.
     */
    await expect(page.getByText(/can sign in once with/)).toBeVisible({ timeout: 20_000 });
    await expect(page.getByText(/It is shown only here/)).toBeVisible();

    /* And they appear in the directory, which is the other half of "added". */
    await expect(page.getByText(address)).toBeVisible();
  });

  test('refuses to strip the workspace of its last owner', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/administration');

    const ownerRow = page.getByRole('row', { name: /Demo Owner/ });
    await ownerRow.getByRole('button', { name: 'Suspend' }).click();

    await expect(page.getByRole('alert')).toContainText('last owner');
  });

  /*
   * The whole onboarding journey, because the parts were each tested and the join was not: an administrator
   * creates a colleague, that colleague signs in with the password they were given, the product refuses to show
   * them anything until they replace it, and the one they were given stops working.
   *
   * It creates a real account with a run-unique address, which is safe in the shared seeded database: a new
   * member is on no project, so they appear in no owner picker and no other test's figures.
   */
  test('onboards a colleague: given a password, forced to replace it, then let in', async ({
    page,
    context,
  }) => {
    const address = `onboarded-${Date.now()}@example.com`;
    const chosen = 'a-passphrase-of-my-own';

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/administration');
    await expect(page.getByText('Add a colleague')).toBeVisible({ timeout: 30_000 });

    await page.getByLabel('Name*', { exact: true }).fill('Onboarded Colleague');
    await page.getByLabel(/Email address/).fill(address);
    await page.getByRole('button', { name: 'Add them' }).click();

    /* The password is shown once, here, and nothing stores it — so the test reads it the way a person would. */
    const announcement = page.getByText(/can sign in once with/);
    await expect(announcement).toBeVisible({ timeout: 20_000 });

    const given = (await announcement.textContent())?.match(/with: (\S+)/)?.[1];
    expect(given).toBeTruthy();

    /* Off the page before clearing its cookies — see the note in `budgets.spec.ts`. */
    await page.goto('about:blank');
    await context.clearCookies();

    await page.goto('/login');
    await page.getByLabel('Email').fill(address);
    await page.getByLabel('Password').fill(given ?? '');
    await page.getByRole('button', { name: 'Sign in' }).click();

    /*
     * The product refuses everything else until the password is theirs, so what they get is the dialog and no
     * way out of it but signing out. A cancel button here would close onto a product that answers nothing.
     */
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 30_000 });
    await expect(dialog.getByText(/given to you by somebody else/)).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toHaveCount(0);

    await dialog.getByLabel('Your current password*', { exact: true }).fill(given ?? '');
    await dialog.getByLabel('New password*', { exact: true }).fill(chosen);
    await dialog.getByLabel('New password again*', { exact: true }).fill(chosen);
    await dialog.getByRole('button', { name: 'Change it' }).click();

    await expect(page.getByText(/The product is open to you now/)).toBeVisible({ timeout: 20_000 });
    await page.getByRole('button', { name: 'Continue' }).click();

    /*
     * And now they are in — on a surface their role allows. A media buyer on no project sees the campaigns
     * page and is told why it is empty, which is the demo's own demonstration of where visibility comes from.
     */
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30_000 });

    /* The password they were given no longer works. */
    await page.goto('about:blank');
    await context.clearCookies();
    await page.goto('/login');
    await page.getByLabel('Email').fill(address);
    await page.getByLabel('Password').fill(given ?? '');
    await page.getByRole('button', { name: 'Sign in' }).click();

    /* The server's own sentence, which says nothing about whether the account exists. */
    await expect(page.getByText('These credentials do not match our records.')).toBeVisible({
      timeout: 20_000,
    });

    /* The one they chose does. */
    await page.getByLabel('Password').fill(chosen);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL(/^(?!.*\/login).*$/, { timeout: 30_000 });
  });

  /*
   * Removing somebody, which used to happen on one unconfirmed click. Removal is irreversible and it releases
   * everything still open in that person's name, so the dialog says what it is about to do and then what it did.
   *
   * The colleague it removes is one it creates, with a run-unique address: the suite shares one seeded database
   * between two browser projects, and removing a demo account would take the other tests' people with it.
   */
  test('removes a colleague and reports what was released', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/administration');
    await expect(page.getByText('Add a colleague')).toBeVisible({ timeout: 30_000 });

    const address = `removed-${Date.now()}@example.com`;

    await page.getByLabel('Name*', { exact: true }).fill('Leaving Colleague');
    await page.getByLabel(/Email address/).fill(address);
    await page.getByRole('button', { name: 'Add them' }).click();
    await expect(page.getByText(address)).toBeVisible({ timeout: 20_000 });

    const row = page.getByRole('row', { name: /Leaving Colleague/ });
    await row.getByRole('button', { name: 'Remove' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('Remove Leaving Colleague from this workspace?');
    /* Somebody added a minute ago is on no project, so they can hold nothing — and the dialog says that. */
    await expect(dialog).toContainText('not holding any open work');

    await dialog.getByRole('button', { name: 'Remove them' }).click();

    await expect(dialog).toContainText('Removed', { timeout: 20_000 });
    await expect(dialog).toContainText('no open work to release');
    await dialog.getByRole('button', { name: 'Close' }).click();

    /* And they are out of the directory, which is the other half of "removed". */
    await expect(page.getByText(address)).toBeHidden();
  });

  test('a media buyer gets a read-only directory and no audit log', async ({ page }) => {
    await signIn(page, ACCOUNTS.buyer);

    // Administration is not in their navigation...
    await page.setViewportSize({ width: 1280, height: 900 });
    await expect(page.getByRole('link', { name: 'Administration' })).toBeHidden();

    // ...but a buyer does hold users.view: they work with these people. What
    // they must not get is role management, suspension or the audit trail.
    await page.goto('/administration');
    await expect(page.getByRole('row', { name: /Demo Owner/ })).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Audit log' })).toBeHidden();
    await expect(page.getByRole('combobox')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Suspend' })).toHaveCount(0);
  });

  test('a client viewer is refused administration outright', async ({ page }) => {
    await signIn(page, ACCOUNTS.client);
    await page.goto('/administration');

    await expect(page.getByRole('alert')).toContainText(
      'does not include workspace administration',
    );
  });
});
