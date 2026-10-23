import AxeBuilder from '@axe-core/playwright';
import { expect, test, type BrowserContextOptions } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * A form, built, published, filled in by a visitor and turned into a lead.
 *
 * The visitor never signs in: the public page is opened in a fresh browser context with no cookies. What is asserted is the
 * whole line — the agency builds it, the visitor's phone shows it without sideways scroll and without accessibility
 * violations, the submission is kept, and the lead appears with the answers mapped to it.
 */
test.describe('tracking forms', () => {
  test('a form is built, published, submitted and becomes a lead', async ({
    page,
    browser,
  }, info) => {
    const stamp = `${info.project.name} r${String(info.retry)} ${String(Date.now())}`;
    const name = `E2E form ${stamp}`;
    const visitor = `Visitor ${stamp}`;
    /* A new number each run: the same number within a day is, correctly, the same lead. */
    const phone = `05${String(Date.now()).slice(-8)}`;

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/tracking?tab=forms');
    await page.getByRole('button', { name: 'New form' }).click();

    await page.getByRole('combobox', { name: 'Project' }).fill('Always');
    await page.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();
    await page.getByLabel('Form name').fill(name);

    for (const [key, type, ar, target] of [
      ['full_name', 'Short text', 'الاسم', 'Lead name'],
      ['mobile', 'Phone', 'الجوال', 'Lead phone'],
    ] as const) {
      await page.getByRole('button', { name: 'Add a field' }).click();
      const field = page.getByRole('group', { name: /^Field \d+$/ }).last();
      await field.getByLabel('Type').selectOption({ label: type });
      await field.getByLabel('Key').fill(key);
      await field.getByLabel('Label (Arabic)').fill(ar);
      await field.getByLabel('Required').check();
      await field.getByLabel('Fills').selectOption({ label: target });
    }

    await page.getByRole('button', { name: 'Save form' }).click();

    const card = page.getByRole('group', { name });
    await expect(card).toBeVisible({ timeout: 30_000 });
    await card.getByRole('button', { name: 'Publish' }).click();
    await expect(card.locator('code')).toBeVisible({ timeout: 30_000 });
    const address = new URL((await card.locator('code').textContent()) ?? '');

    const context = await browser.newContext(info.project.use as BrowserContextOptions);
    const anonymous = await context.newPage();
    await anonymous.goto(address.pathname);
    await expect(anonymous.getByRole('heading', { name })).toBeVisible({ timeout: 30_000 });
    await expectNoSidewaysScroll(anonymous);

    const { violations } = await new AxeBuilder({ page: anonymous })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
      .analyze();
    expect(violations.map((v) => v.id)).toEqual([]);

    await anonymous.getByRole('button', { name: 'إرسال' }).click();
    await expect(anonymous.getByText('هذا الحقل مطلوب').first()).toBeVisible();

    await anonymous.getByLabel(/الاسم/).fill(visitor);
    await anonymous.getByLabel(/الجوال/).fill(phone);
    await anonymous.getByRole('button', { name: 'إرسال' }).click();
    await expect(anonymous.getByRole('status')).toBeVisible({ timeout: 30_000 });
    await context.close();

    await page.reload();
    await expect(page.getByRole('group', { name }).getByText('1', { exact: true })).toBeVisible({
      timeout: 30_000,
    });

    /* Same origin as the signed-in page, so the session cookie goes with it. */
    const found = await page.evaluate(async (term) => {
      const response = await fetch(`/api/leads?search=${encodeURIComponent(term)}&per_page=100`, {
        headers: { Accept: 'application/json' },
        credentials: 'include',
      });

      return response.text();
    }, visitor);
    expect(found).toContain(visitor);
  });
});
