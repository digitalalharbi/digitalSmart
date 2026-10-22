import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Asking for an account from the landing page, and answering it in Administration.
 *
 * A request creates nothing: what is asserted is that the visitor is told it was received, that an administrator sees
 * it, and that deciding on it is recorded without anybody being added.
 */
test.describe('requests for an account', () => {
  test('a visitor asks, an administrator answers', async ({ page }, info) => {
    const who = `Request ${info.project.name} ${String(Date.now())}`;

    await page.goto('/request-access');
    await expect(page.locator('h1')).toBeVisible({ timeout: 30_000 });

    test.skip(
      (await page.getByRole('button', { name: 'Send the request' }).count()) === 0,
      'This installation does not take requests for an account.',
    );

    await page.getByLabel('Full name').fill(who);
    await page.getByLabel('Email').fill(`${who.replaceAll(' ', '.').toLowerCase()}@example.test`);
    await page.getByRole('radio', { name: /manage campaigns for my clients/i }).click();
    await page.getByRole('button', { name: 'Send the request' }).click();

    await expect(page.getByText('Your request has been received')).toBeVisible({ timeout: 30_000 });

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/administration?section=requests');

    const card = page.getByRole('listitem').filter({ hasText: who });

    await expect(card).toBeVisible({ timeout: 30_000 });
    await card.getByRole('button', { name: 'Mark as contacted' }).click();
    await page.getByRole('button', { name: /^Contacted/ }).click();
    await expect(page.getByRole('listitem').filter({ hasText: who })).toBeVisible();
  });
});
