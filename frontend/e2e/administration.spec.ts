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
