import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * The restructured surfaces, measured under WCAG 2.2 AA in the two appearances that differ most from the default:
 * Arabic right-to-left in the dark, and English in the light. Contrast is the rule most likely to break in the dark
 * and layout order the one most likely to break right-to-left, and both are caught here by the same published rule set
 * `accessibility.spec.ts` uses. A pass is "no machine-detectable violation", which is a floor and not the claim of
 * being accessible — see the note there.
 */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const SURFACES = [
  '/',
  '/campaigns',
  '/creative-hub',
  '/budget-control',
  '/tracking',
  '/leads',
  '/reports',
  '/presentations',
  '/integrations',
  '/administration',
  '/administration?section=roles',
  '/portfolio',
];

async function appearance(page: Page, theme: 'light' | 'dark', locale: 'en' | 'ar'): Promise<void> {
  await page.evaluate(
    ([value, language]) => {
      localStorage.setItem(
        'digitalsmart.ui',
        JSON.stringify({ state: { theme: value, locale: language }, version: 1 }),
      );
    },
    [theme, locale],
  );
}

for (const [theme, locale] of [
  ['light', 'en'],
  ['dark', 'ar'],
] as const) {
  test(`restructured surfaces have no machine-detectable violation (${theme}, ${locale})`, async ({
    page,
  }) => {
    await signIn(page, ACCOUNTS.owner);
    await appearance(page, theme, locale);

    const failures: string[] = [];

    for (const path of SURFACES) {
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({
        timeout: 30_000,
      });
      await page.waitForLoadState('networkidle').catch(() => undefined);

      const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();

      for (const violation of violations) {
        failures.push(
          `${path} — ${violation.id} (${violation.impact ?? 'unknown'}): ${violation.nodes
            .slice(0, 3)
            .map((node) => node.target.join(' '))
            .join(', ')}`,
        );
      }
    }

    expect(failures).toEqual([]);
  });
}

test('a project workspace has no machine-detectable violation, in each tab', async ({ page }) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/projects');
  await page
    .getByRole('button', { name: /^Actions for/ })
    .first()
    .click({ timeout: 30_000 });
  await page.getByRole('menuitem', { name: 'Open project workspace' }).click();
  await expect(page.getByRole('tablist', { name: 'Areas of this project' })).toBeVisible({
    timeout: 30_000,
  });

  const failures: string[] = [];

  for (const tab of ['Overview', 'Campaigns', 'Budget', 'Creative', 'Tasks', 'Activity']) {
    await page.getByRole('tab', { name: tab }).click();
    await page.waitForLoadState('networkidle').catch(() => undefined);

    const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();

    for (const violation of violations) {
      failures.push(
        `${tab} — ${violation.id}: ${violation.nodes
          .slice(0, 3)
          .map((node) => node.target.join(' '))
          .join(', ')}`,
      );
    }
  }

  expect(failures).toEqual([]);
});
