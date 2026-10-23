import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * Conversion mappings.
 *
 * What is asserted: a provider whose documentation has not been read cannot be mapped, one that is documented can, the
 * mapping appears and can be removed, the consent policy saves, and the page fits a phone.
 */
test.describe('tracking conversions', () => {
  test('a documented provider can be mapped and an unverified one cannot', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/tracking?tab=conversions');
    await page.getByRole('combobox', { name: 'Project' }).fill('Always');
    await page.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();

    await expect(page.getByText('What each provider documents')).toBeVisible({ timeout: 30_000 });
    const provider = page.getByRole('combobox', { name: 'Provider' });
    await expect(provider.getByRole('option', { name: /TikTok.*Not verified/ })).toBeDisabled();
    await expect(provider.getByRole('option', { name: /^X.*Unsupported/ })).toBeDisabled();

    await page.getByLabel('digitalSmart event').selectOption({ label: 'Lead created' });
    await provider.selectOption({ label: 'Snapchat Ads' });
    await page.getByLabel('Provider event').selectOption('SIGN_UP');
    await page.getByRole('button', { name: 'Save mapping' }).click();

    const mapping = page
      .getByRole('listitem')
      .filter({ hasText: /Lead created → Snapchat.*SIGN_UP/ });
    await expect(mapping).toBeVisible({ timeout: 30_000 });

    await page.getByLabel('Consent policy').selectOption('required');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByLabel('Keep raw events for (days)')).toHaveValue('400');
    await expectNoSidewaysScroll(page);

    await mapping.getByRole('button', { name: 'Remove' }).click();
    await expect(mapping).toHaveCount(0, { timeout: 30_000 });
  });
});
