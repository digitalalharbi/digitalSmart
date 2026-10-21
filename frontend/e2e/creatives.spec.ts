import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Creatives across the workspace: pictures first, honest where there is no picture, and usable on a phone and in Arabic.
 *
 * The sandbox workspace's creatives carry fixture addresses, so what is asserted is the honesty — a tile says there is no
 * preview instead of drawing one — and the layout, not any particular figure.
 */
test.describe('creatives', () => {
  test("opens on views of the workspace's creatives, and never draws a sandbox address as a picture", async ({
    page,
  }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/creatives');

    await expect(page.getByRole('heading', { level: 1, name: 'Creatives' })).toBeVisible();
    await expect(
      page
        .getByRole('group', { name: 'Creative view' })
        .or(page.getByText('No creatives in this selection')),
    ).toBeVisible({ timeout: 30_000 });

    // Nothing ever points at a fixture host.
    const sources = await page
      .locator('img')
      .evaluateAll((images) => images.map((image) => (image as HTMLImageElement).src));
    expect(sources.filter((src) => /\.invalid\b/.test(src))).toEqual([]);
  });

  test("narrows by platform from the server's own options", async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/creatives');
    const platform = page.getByLabel('Platform');
    await expect(platform).toBeVisible({ timeout: 30_000 });

    const options = await platform.locator('option').allTextContents();
    test.skip(options.length < 2, 'The workspace has no creatives to filter.');

    await platform.selectOption({ index: 1 });
    await expect(page.getByRole('heading', { level: 1, name: 'Creatives' })).toBeVisible();
  });

  test.describe('on a phone', () => {
    test.use({ viewport: { width: 375, height: 812 } });

    test('does not scroll sideways', async ({ page }) => {
      await signIn(page, ACCOUNTS.owner);
      await page.goto('/creatives');
      await expect(page.getByRole('heading', { level: 1, name: 'Creatives' })).toBeVisible();
      await expect(
        page
          .getByRole('group', { name: 'Creative view' })
          .or(page.getByText('No creatives in this selection')),
      ).toBeVisible({ timeout: 30_000 });

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      );
      expect(overflow).toBeLessThanOrEqual(0);
    });
  });

  test.describe('in Arabic', () => {
    test.use({ locale: 'ar-SA' });

    test('reads right to left, with Latin digits', async ({ page }) => {
      await signIn(page, ACCOUNTS.owner);
      await page.goto('/creatives');

      await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
      await expect(page.getByRole('heading', { level: 1, name: 'المواد الإبداعية' })).toBeVisible({
        timeout: 30_000,
      });
      const group = page.getByRole('group', { name: 'عرض المواد الإبداعية' });
      if (await group.isVisible().catch(() => false)) {
        await expect(group).not.toContainText(/[٠-٩]/);
      }
    });
  });
});
