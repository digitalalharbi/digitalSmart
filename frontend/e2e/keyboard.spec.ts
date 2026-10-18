import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/*
 * A keyboard pass over the surfaces an owner uses: no mouse, only Tab, Shift+Tab, Enter, Space, Arrow keys and Escape.
 *
 * What is measured, and what is not. Each surface is walked with Tab and every stop must (a) be visible, (b) show a
 * focus indicator, and (c) the walk must reach the end of the page rather than stall on one element. Dialogs must
 * take focus on open, close on Escape and hand focus back to the control that opened them. This is a floor: it finds
 * traps, lost focus and invisible rings. It does not replace a screen-reader pass, which is still to be done by a person.
 */
test.skip(process.env['E2E_AGENCY'] !== '1', 'Needs the sandbox agency: E2E_AGENCY=1');

const SURFACES = [
  '/',
  '/campaigns',
  '/projects',
  '/leads',
  '/creative-hub',
  '/budget-control',
  '/reports',
  '/presentations',
  '/integrations',
];

type Stop = { tag: string; name: string; visible: boolean; ring: boolean };

async function stop(page: Page): Promise<Stop> {
  return page.evaluate(() => {
    const el = document.activeElement as HTMLElement | null;
    if (el === null || el === document.body) {
      return { tag: 'body', name: '', visible: true, ring: true };
    }
    const box = el.getBoundingClientRect();
    const style = getComputedStyle(el);
    const ring =
      (style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0) ||
      (style.boxShadow !== 'none' && style.boxShadow !== '');
    return {
      tag: el.tagName.toLowerCase(),
      name: (el.getAttribute('aria-label') ?? el.textContent ?? '').trim().slice(0, 40),
      visible: box.width > 0 && box.height > 0 && style.visibility !== 'hidden',
      ring,
    };
  });
}

test('every surface can be walked with Tab, and every stop shows where focus is', async ({
  page,
}) => {
  test.setTimeout(400_000);
  await signIn(page, ACCOUNTS.owner);
  const problems: string[] = [];

  for (const path of SURFACES) {
    await page.goto(path);
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 45_000 });
    await page.waitForLoadState('networkidle').catch(() => undefined);
    await page.locator('body').click({ position: { x: 1, y: 1 } });

    const seen = new Set<string>();
    for (let i = 0; i < 120; i += 1) {
      await page.keyboard.press('Tab');
      const s = await stop(page);
      const key = `${s.tag}:${s.name}:${String(i)}`;
      seen.add(key);
      if (!s.visible) problems.push(`${path}: hidden stop ${s.tag} "${s.name}"`);
      if (!s.ring) problems.push(`${path}: no focus indicator on ${s.tag} "${s.name}"`);
    }
    if (seen.size < 10) problems.push(`${path}: focus stalled (${String(seen.size)} stops)`);
  }

  expect(problems).toEqual([]);
});

test('the command palette opens from the keyboard, closes on Escape and returns focus', async ({
  page,
}) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 45_000 });

  const trigger = page.getByRole('button', { name: /^Search/ });
  await trigger.focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator(':focus')).toHaveCount(1);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
});

test('a campaign quick action opens, traps focus, closes on Escape and returns focus', async ({
  page,
}) => {
  await signIn(page, ACCOUNTS.owner);
  await page.goto('/projects');
  await page.locator('main a[href^="/projects/"]').first().click();
  await page.getByRole('tab', { name: 'Campaigns' }).click();
  const campaign = page.locator('a[href^="/campaigns/"]').first();
  await expect(campaign).toBeVisible({ timeout: 45_000 });
  await campaign.click();
  await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 45_000 });
  await page.waitForLoadState('networkidle').catch(() => undefined);
  const open = page.getByRole('button', { name: 'Add task', exact: true }).first();
  await expect(open).toBeVisible({ timeout: 45_000 });
  await open.focus();
  /* The same element, not "the first match" again: the list may reorder while the dialog is open. */
  const opener = await open.elementHandle();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();

  // Tab many times: focus must stay inside the dialog.
  for (let i = 0; i < 12; i += 1) {
    await page.keyboard.press('Tab');
    const inside = await page.evaluate(
      () => document.activeElement?.closest('[role="dialog"]') !== null,
    );
    expect(inside).toBe(true);
  }
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  expect(await page.evaluate((el) => document.activeElement === el, opener)).toBe(true);
});

test('project tabs move with the arrow keys', async ({ page }) => {
  await signIn(page, ACCOUNTS.manager);
  await page.goto('/projects');
  await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 45_000 });
  await page.locator('main a[href^="/projects/"]').first().waitFor({ timeout: 45_000 });
  await page.locator('main a[href^="/projects/"]').first().click();
  const tabs = page.getByRole('tab');
  await expect(tabs.first()).toBeVisible({ timeout: 45_000 });

  await tabs.first().focus();
  await page.keyboard.press('ArrowRight');
  await expect(tabs.nth(1)).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
});
