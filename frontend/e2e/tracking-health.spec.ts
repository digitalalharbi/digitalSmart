import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * Tracking health and the test console.
 *
 * What is asserted: health never shows a provider pixel as working, a test visit is watched arriving in the console and is
 * labelled as a test, and both fit a phone.
 */
test.describe('tracking health and test console', () => {
  test('health is honest about pixels and a test visit shows up in the console', async ({
    page,
    request,
  }, info) => {
    const name = `E2E health ${info.project.name} r${String(info.retry)} ${String(Date.now())}`;

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/tracking?tab=links');
    await page.getByRole('button', { name: 'New tracking link' }).click();

    const dialog = page.getByRole('dialog');
    await dialog.getByRole('combobox', { name: 'Project' }).fill('Always');
    await dialog.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();
    await dialog.getByLabel('Link name').fill(name);
    await dialog.getByLabel('Destination page').fill('https://shop.example.com/health');
    await dialog.getByRole('button', { name: 'Create link' }).click();
    await expect(page.getByRole('group', { name })).toBeVisible({ timeout: 30_000 });

    await page.goto('/tracking?tab=health');
    await page.getByRole('combobox', { name: 'Project' }).fill('Always');
    await page.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();

    const meta = page.getByRole('listitem').filter({ hasText: 'Meta — browser' });
    await expect(meta).toBeVisible({ timeout: 30_000 });
    await expect(meta.getByText('Not configured')).toBeVisible();
    await expect(
      page.getByRole('listitem').filter({ hasText: 'Deduplication' }).getByText('Not configured'),
    ).toBeVisible();
    await expect(
      page.getByRole('listitem').filter({ hasText: 'Google Analytics 4' }),
    ).toContainText(/Not configured|Awaiting credentials|Sandbox|Synced|Stale/);
    await expectNoSidewaysScroll(page);

    await page.goto('/tracking?tab=events');
    await page.getByRole('combobox', { name: 'Project' }).fill('Always');
    await page.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();
    await page.getByLabel('Tracking link').selectOption({ label: name });
    await page.getByRole('button', { name: 'Start a test visit' }).click();

    const address = await page
      .getByRole('link', { name: /shop\.example\.com\/health\?/ })
      .textContent();
    const touchpoint = new URL(address ?? '').searchParams.get('ds_tp') ?? '';
    expect(touchpoint).toHaveLength(20);

    const beacon = await request.post('/api/public/track', {
      headers: { 'content-type': 'text/plain' },
      data: JSON.stringify({
        touchpoint,
        event: 'landing_view',
        event_id: `e2e-${String(Date.now())}`,
      }),
    });
    expect(beacon.status()).toBe(202);

    const eventConsole = page.getByRole('region', { name: 'Event console' });
    await expect(eventConsole.getByRole('cell', { name: 'landing_view' })).toBeVisible({
      timeout: 30_000,
    });
    await expect(eventConsole.getByRole('cell', { name: 'link_click' })).toBeVisible();
    await expect(eventConsole.getByText('Test').first()).toBeVisible();
    await expect(
      page.getByRole('textbox', { name: 'Data layer name for form_submit' }),
    ).toHaveValue('ds_form_submit');
    const offenders = await page.evaluate(() => {
      const root = document.documentElement;
      const over = (): number => root.scrollWidth - root.clientWidth;

      if (over() <= 0) {
        return [];
      }

      const culprits: string[] = [];

      for (const el of document.querySelectorAll<HTMLElement>('body *')) {
        const before = el.style.display;

        el.style.display = 'none';
        const fixed = over() <= 0;

        el.style.display = before;

        if (fixed) {
          culprits.push(
            `${el.tagName}.${String(el.className).slice(0, 70)} w=${String(Math.round(el.getBoundingClientRect().width))} text=${(el.textContent ?? '').slice(0, 40)}`,
          );
        }
      }

      return [`over=${String(over())}`, ...culprits.slice(-6)];
    });

    expect(offenders, 'elements drawn past the screen').toEqual([]);
    await expectNoSidewaysScroll(page);
  });
});
