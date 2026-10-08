import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, DEMO_PASSWORD, signIn } from './support/accounts';

/**
 * WCAG 2.2 AA, measured.
 *
 * The brief names a standard, and a standard nobody runs is a sentence in a document. This runs the published
 * rule set — axe-core's `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa` and `wcag22aa` tags — against the real
 * application, signed in as a real role, and fails on any violation.
 *
 * What it cannot check is stated rather than implied. Automated rules catch contrast, names, roles, labels,
 * landmarks and order; they cannot tell whether a label *describes* the control, whether an alternative conveys
 * the same information, or whether a flow can be completed by keyboard alone. So this suite is a floor and the
 * product's own tests carry the rest: `ChartFrame`'s table twin exists because a canvas tells a screen reader
 * nothing, the chart palette has its own measured colour-blindness gates, and the typography tests assert the
 * Arabic face and digit shapes. A green run here means no machine-detectable violation, which is not the same
 * claim as "accessible" — and saying so is the point.
 */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

async function violationsOn(page: Page, path: string, heading: RegExp): Promise<string[]> {
  await page.goto(path);
  await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible({
    timeout: 30_000,
  });

  const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();

  /*
   * Reported as "rule: which elements", because a count is not actionable. A failure here should name the
   * selector somebody has to open.
   */
  return violations.map(
    (violation) =>
      `${violation.id} (${violation.impact ?? 'unknown'}): ${violation.nodes
        .map((node) => node.target.join(' '))
        .join(', ')}`,
  );
}

test.describe('accessibility', () => {
  /*
   * The surfaces chosen are the ones whose markup is unlike the others': a form-heavy screen, a table, a
   * chart-led screen with a table twin, a board of interactive cards, and the one page a client sees. A pass on
   * five pages built from the same primitives is a pass on the primitives.
   */
  const SURFACES: { path: string; heading: RegExp; as: string }[] = [
    { path: '/clients', heading: /Clients/, as: ACCOUNTS.owner },
    { path: '/analytics', heading: /Analytics/, as: ACCOUNTS.owner },
    { path: '/tasks', heading: /Operations/, as: ACCOUNTS.owner },
    { path: '/approvals', heading: /Approvals/, as: ACCOUNTS.owner },
  ];

  for (const surface of SURFACES) {
    test(`has no machine-detectable WCAG violation on ${surface.path}`, async ({ page }) => {
      await signIn(page, surface.as);

      expect(await violationsOn(page, surface.path, surface.heading)).toEqual([]);
    });
  }

  test('has no machine-detectable WCAG violation on the sign-in screen', async ({ page }) => {
    await page.goto('/login');

    /*
     * Before a session, which is the one screen every user meets and the only one a person locked out of the
     * product cannot work around.
     */
    await expect(page.getByLabel(/Email/)).toBeVisible({ timeout: 30_000 });

    const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();

    expect(violations.map((violation) => violation.id)).toEqual([]);
  });

  /*
   * The backup channel for readers colour cannot reach. The palette clears every colour-blindness gate it is
   * measured against, and that is still nothing for somebody who sees no colour at all, or for a report printed
   * in grey — so patterns are offered where the problem is noticed, beside the chart.
   */
  test('offers patterns beside every chart, and remembers the choice', async ({ page }) => {
    await signIn(page, ACCOUNTS.owner);
    await page.goto('/analytics');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30_000 });

    const toggle = page.getByRole('button', { name: 'Patterns' }).first();

    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');

    /*
     * Persisted, because somebody who needs patterns needs them on every page and on every visit. Asking again
     * tomorrow would be asking them to tell us about their eyesight daily.
     */
    await page.reload();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('button', { name: 'Patterns' }).first()).toHaveAttribute(
      'aria-pressed',
      'true',
    );

    /* Left as it was found, because the whole suite shares one browser profile per project. */
    await page.getByRole('button', { name: 'Patterns' }).first().click();
    await expect(page.getByRole('button', { name: 'Patterns' }).first()).toHaveAttribute(
      'aria-pressed',
      'false',
    );
  });

  /*
   * Its own describe block with `test.use`, rather than a hand-built context: the language is chosen by the
   * browser and the suite pins `locale` for everything else, and this is the supported way to unpin it for one
   * group. RTL is not a mirror of LTR — the direction decides which side a label sits on, which corner a focus
   * ring is clipped by, and whether an axis label still has room.
   */
  test.describe('in Arabic, right to left', () => {
    test.use({ locale: 'ar-SA' });

    test('has no machine-detectable WCAG violation on a chart-led surface', async ({ page }) => {
      /*
       * Signed in through the Arabic interface rather than the shared English helper, because an Arabic browser
       * gets the Arabic sign-in screen — which is the behaviour `localisation.spec.ts` exists to assert.
       */
      await page.goto('/login');
      await page.getByLabel('البريد الإلكتروني').fill(ACCOUNTS.owner);
      await page.getByLabel('كلمة المرور').fill(DEMO_PASSWORD);
      await page.getByRole('button', { name: 'تسجيل الدخول' }).click();
      await page.waitForURL((url) => !url.pathname.startsWith('/login'));

      await page.goto('/analytics');
      await expect(page.locator('html')).toHaveAttribute('dir', 'rtl', { timeout: 30_000 });
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();

      const { violations } = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();

      expect(
        violations.map(
          (violation) =>
            `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`,
        ),
      ).toEqual([]);
    });
  });
});
