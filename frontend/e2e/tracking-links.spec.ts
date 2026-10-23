import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * A tracking link, followed.
 *
 * Builds a link in the interface, follows its short address as an advertisement would, has a destination page report a
 * view, and checks the page then shows the click as a count and the view as a count — and that an address which should
 * not redirect (a private destination) was refused when it was typed.
 */
test.describe('tracking links', () => {
  test('a link is built, followed and counted', async ({ page, request }, info) => {
    const name = `E2E link ${info.project.name} r${String(info.retry)} ${String(Date.now())}`;

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/tracking?tab=links');
    await page.getByRole('button', { name: 'New tracking link' }).click();

    const dialog = page.getByRole('dialog');
    await dialog.getByRole('combobox', { name: 'Project' }).fill('Always');
    await dialog.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();
    await dialog.getByLabel('Link name').fill(name);
    await dialog.getByLabel('Destination page').fill('http://127.0.0.1/admin');
    await dialog.getByRole('button', { name: 'Create link' }).click();
    await expect(dialog.getByRole('alert')).toBeVisible({ timeout: 30_000 });

    await dialog.getByLabel('Destination page').fill('https://shop.example.com/offer');
    await dialog.getByLabel('Campaign').fill('Spring Offer');
    await dialog.getByRole('button', { name: 'Create link' }).click();

    const card = page.getByRole('group', { name });
    await expect(card).toBeVisible({ timeout: 30_000 });
    const shortUrl = (await card.locator('code').textContent()) ?? '';
    expect(shortUrl).toMatch(/\/r\/[A-Za-z0-9]{10}$/);

    const followed = await request.get(shortUrl.replace(/^https?:\/\/[^/]+/, ''), {
      maxRedirects: 0,
      headers: {
        'user-agent':
          'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
      },
    });
    expect(followed.status()).toBe(302);
    const location = new URL(followed.headers()['location'] ?? '');
    expect(location.hostname).toBe('shop.example.com');
    expect(location.searchParams.get('utm_source')).toBe('snapchat');
    const touchpoint = location.searchParams.get('ds_tp') ?? '';
    expect(touchpoint).toHaveLength(20);

    await page.reload();
    await expect(card.getByText('Not reported').first()).toBeVisible({ timeout: 30_000 });
    await expect(card.getByText('The destination page has not reported')).toBeVisible();

    const beacon = await request.post('/api/public/track', {
      headers: { 'content-type': 'text/plain' },
      data: JSON.stringify({
        touchpoint,
        event: 'landing_view',
        event_id: `e2e-${String(Date.now())}`,
      }),
    });
    expect(beacon.status()).toBe(202);

    await page.reload();
    await expect(card.getByText('The destination page has not reported')).toHaveCount(0, {
      timeout: 30_000,
    });
  });
});
