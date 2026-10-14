import { expect, test } from '@playwright/test';
import { ACCOUNTS, DEMO_PASSWORD } from './support/accounts';

/**
 * The rest of the suite pins en-US for stable selectors. This file deliberately
 * does not: Arabic is a first-class language here, and an Arabic browser must
 * get the Arabic interface without anyone touching a toggle.
 */
test.describe('an Arabic browser', () => {
  test.use({ locale: 'ar-SA' });

  test('gets the Arabic interface, right to left, from the first paint', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(
      page.getByRole('heading', { name: 'تسجيل الدخول إلى digitalSmart' }),
    ).toBeVisible();
  });

  test('signs in and keeps Arabic throughout the app', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('البريد الإلكتروني').fill(ACCOUNTS.owner);
    await page.getByLabel('كلمة المرور').fill(DEMO_PASSWORD);
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();

    await expect(page.getByRole('heading', { level: 1, name: 'نظرة عامة' })).toBeVisible();

    await page.goto('/clients');
    await expect(page.getByRole('heading', { level: 1, name: 'العملاء' })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  });

  test('keeps Latin digits so figures reconcile with platform dashboards', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('البريد الإلكتروني').fill(ACCOUNTS.owner);
    await page.getByLabel('كلمة المرور').fill(DEMO_PASSWORD);
    await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
    // Let sign-in settle before navigating, or the next page load races the session.
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.goto('/clients');

    /*
     * The table view, which is where the project counts are read as a column. The page opens on cards now,
     * and both layouts carry the same figures — the digits are the point of this test, not the layout.
     */
    await page.getByRole('button', { name: 'جدول', exact: true }).click();
    await expect(page.getByRole('row', { name: /Alhambra/ })).toBeVisible();

    const table = await page.locator('table').innerText();

    // The table carries project counts. Arabic-Indic digits (٠١٢…) would not
    // match the figures a media buyer reads off a platform dashboard.
    expect(table).toMatch(/[0-9]/);
    expect(table).not.toMatch(/[٠-٩]/);
  });
});
