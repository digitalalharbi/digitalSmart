import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * A funnel, read back from real traffic.
 *
 * A link is built and followed as an advertisement would, and the Funnels tab is then asked about the project. What is
 * asserted: the click is counted, the step no page reported is shown as not reported rather than zero, and the page fits a
 * phone.
 */
test.describe('tracking funnels', () => {
  test('a followed link is counted and an unreported step is not a zero', async ({
    page,
    request,
  }, info) => {
    const name = `E2E funnel ${info.project.name} r${String(info.retry)} ${String(Date.now())}`;

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/tracking?tab=links');
    await page.getByRole('button', { name: 'New tracking link' }).click();

    const dialog = page.getByRole('dialog');
    await dialog.getByRole('combobox', { name: 'Project' }).fill('Always');
    await dialog.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();
    await dialog.getByLabel('Link name').fill(name);
    await dialog.getByLabel('Destination page').fill('https://shop.example.com/funnel');
    await dialog.getByRole('button', { name: 'Create link' }).click();

    const card = page.getByRole('group', { name });
    await expect(card).toBeVisible({ timeout: 30_000 });
    const shortUrl = (await card.locator('code').textContent()) ?? '';
    const followed = await request.get(shortUrl.replace(/^https?:\/\/[^/]+/, ''), {
      maxRedirects: 0,
      headers: {
        'user-agent':
          'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
      },
    });
    expect(followed.status()).toBe(302);

    await card.getByRole('button', { name: 'Show funnel' }).click();
    await expect(card.getByRole('list', { name: 'Funnel' })).toBeVisible({ timeout: 30_000 });
    await expect(
      card.getByText('The destination page has not reported this step.').first(),
    ).toBeVisible();

    await page.goto('/tracking?tab=funnels');
    await page.getByRole('combobox', { name: 'Project' }).fill('Always');
    await page.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();

    const funnel = page.getByRole('list', { name: 'Funnel' }).first();
    await expect(funnel).toBeVisible({ timeout: 30_000 });
    await expect(funnel.getByRole('listitem').first()).toContainText('Link click');
    await expect(page.getByText('Follow-up')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Breakdown' })).toBeVisible({ timeout: 30_000 });
    await expectNoSidewaysScroll(page);
  });
});
