import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * Visual QA evidence: one screenshot of every main surface in the four situations that differ most — desktop and phone,
 * light English and dark Arabic. Not an assertion about pixels (those drift with every font update); a record somebody can
 * open to look, written to `test-results/qa/`. Runs only on request: `QA_SHOTS=1 E2E_AGENCY=1 scripts/e2e.sh e2e/qa-screenshots.spec.ts`.
 */
test.skip(process.env['QA_SHOTS'] !== '1', 'Evidence run: QA_SHOTS=1');

const SURFACES: Array<{ name: string; path: string; as?: string }> = [
  { name: 'executive-overview', path: '/' },
  { name: 'campaign-control', path: '/campaigns' },
  { name: 'budget-control', path: '/budget-control' },
  { name: 'lead-center', path: '/leads' },
  { name: 'creative-hub', path: '/creative-hub' },
  { name: 'tracking', path: '/tracking' },
  { name: 'commerce', path: '/commerce' },
  { name: 'reports', path: '/reports' },
  { name: 'presentations', path: '/presentations' },
  { name: 'integrations', path: '/integrations' },
  { name: 'administration', path: '/administration' },
  { name: 'portfolio', path: '/portfolio' },
  { name: 'media-buyer-home', path: '/', as: ACCOUNTS.buyer },
  { name: 'finance-home', path: '/', as: ACCOUNTS.finance },
  { name: 'creative-home', path: '/', as: ACCOUNTS.creative },
  { name: 'client-portal', path: '/portal', as: ACCOUNTS.client },
];

const SITUATIONS = [
  { key: 'desktop-light-en', viewport: { width: 1360, height: 860 }, theme: 'light', locale: 'en' },
  { key: 'desktop-dark-ar', viewport: { width: 1360, height: 860 }, theme: 'dark', locale: 'ar' },
  { key: 'mobile-light-en', viewport: { width: 390, height: 844 }, theme: 'light', locale: 'en' },
  { key: 'mobile-dark-ar', viewport: { width: 390, height: 844 }, theme: 'dark', locale: 'ar' },
] as const;

async function settle(page: Page): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 45_000 });
  await page.waitForLoadState('networkidle').catch(() => undefined);
  await page.waitForTimeout(600);
}

for (const situation of SITUATIONS) {
  test.describe(situation.key, () => {
    /*
     * One browser context per person. Signing a second account in on the same context leaves the first one's session
     * behind it, and the app (correctly) keeps showing that one.
     */
    const people = [...new Set(SURFACES.map((surface) => surface.as ?? ACCOUNTS.owner))];

    for (const account of people) {
      test(`records ${account.split('@')[0] ?? account}'s surfaces`, async ({ browser }) => {
        test.setTimeout(300_000);
        const context = await browser.newContext({ viewport: situation.viewport });
        const page = await context.newPage();

        await signIn(page, account);
        await page.evaluate(
          ([theme, locale]) => {
            localStorage.setItem(
              'digitalsmart.ui',
              JSON.stringify({ state: { theme, locale }, version: 1 }),
            );
          },
          [situation.theme, situation.locale],
        );

        for (const surface of SURFACES.filter(
          (entry) => (entry.as ?? ACCOUNTS.owner) === account,
        )) {
          await page.goto(surface.path);
          await settle(page);
          await page.screenshot({
            path: `test-results/qa/${situation.key}/${surface.name}.png`,
            fullPage: false,
          });
        }

        await context.close();
      });
    }
  });
}
