import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

const SLOW = { timeout: 30_000 };

async function expectNoViolations(page: Page, what: string): Promise<void> {
  const { violations } = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();

  expect(
    violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`),
    `axe on ${what}`,
  ).toEqual([]);
}

/**
 * A campaign planned once and kept: started, filled in step by step, submitted for review, and read in each platform's own words
 * — with nothing created on any platform.
 */
test('a campaign plan is kept from its first step, submitted, and shows each platform’s launch plan', async ({
  page,
}, info) => {
  test.setTimeout(120_000);
  const name = `E2E plan ${info.project.name}-r${String(info.retry)}-${String(Date.now())}`;

  await signIn(page, ACCOUNTS.owner);
  await page.goto('/campaigns/new');
  const first = page.getByRole('region', { name: 'Client & project' });
  await expect(first.getByLabel('Project')).toBeVisible(SLOW);

  await first.getByLabel('Project').selectOption({ index: 1 });
  await first.getByLabel('Campaign name').fill(name);
  await page.getByRole('button', { name: 'Next' }).click();
  await page.waitForURL(/\/campaigns\/plans\/[0-9a-f-]{36}$/, SLOW);

  // Platforms & accounts: whatever this project has bound.
  const accounts = page.getByRole('group', { name: /Ad accounts bound/ }).getByRole('checkbox');
  await expect(accounts.first()).toBeVisible(SLOW);
  await accounts.first().check();
  for (let i = 0; i < 5; i++) {
    await page.getByRole('button', { name: 'Next' }).click();
  }

  // Tracking & conversion.
  await page
    .getByRole('region', { name: 'Tracking & conversion' })
    .getByLabel('Landing page')
    .fill('https://example.test/eid');
  await page.getByRole('button', { name: 'Next' }).click();

  // Creatives & copy.
  await page
    .getByRole('region', { name: 'Creatives' })
    .getByLabel('Headline', { exact: true })
    .fill('Up to 40% off');
  await page.getByRole('button', { name: 'Next' }).click();

  // Review.
  await page.getByRole('button', { name: 'Submit for review' }).click();
  await expect(page.getByText('Ready for review').first()).toBeVisible(SLOW);

  // Launch plan: each platform's own words, created without delivering.
  await page.getByRole('button', { name: 'Next' }).click();
  await expect(page.getByText('Created without delivering').first()).toBeVisible(SLOW);
  await expectNoViolations(page, 'the launch plan');

  await page.goto('/campaigns/plans');
  await expect(page.getByRole('link', { name: new RegExp(name) })).toBeVisible(SLOW);
  await expectNoViolations(page, 'the plans list');
});
