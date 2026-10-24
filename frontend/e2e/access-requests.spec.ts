import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * Asking for an account, and being answered all the way to a first sign-in.
 *
 * What is asserted: a visitor is told one generic thing; the people who answer see the request, try it, approve it and make
 * an invitation; no account exists until the invitation is accepted; and the first sign-in is recorded only when it happens.
 * Nothing here is sent — email and WhatsApp say they are awaiting credentials — and every screen fits a 375px phone, in
 * English and in Arabic.
 */

const PASSWORD = 'a-long-chosen-passphrase';

function stamp(info: { project: { name: string }; retry: number }): string {
  return `${info.project.name}-r${String(info.retry)}-${String(Date.now())}`.replaceAll(
    /[^a-z0-9-]/gi,
    '',
  );
}

async function setLocale(page: Page, locale: 'en' | 'ar'): Promise<void> {
  await page.addInitScript((value) => {
    localStorage.setItem(
      'digitalsmart.ui',
      JSON.stringify({ state: { theme: 'light', locale: value }, version: 1 }),
    );
  }, locale);
}

async function expectNoViolations(page: Page, what: string): Promise<void> {
  const { violations } = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();

  expect(
    violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`),
    `axe on ${what}`,
  ).toEqual([]);
}

/** Fill and send the public form. Returns false when this installation takes no requests. */
async function ask(
  page: Page,
  input: { path?: string; name: string; email: string; phone?: string; enquiry?: boolean },
): Promise<boolean> {
  await page.goto(input.path ?? '/request-access');
  await expect(page.locator('h1')).toBeVisible({ timeout: 30_000 });

  if ((await page.getByRole('button', { name: 'Send the request' }).count()) === 0) {
    return false;
  }

  await page.getByLabel('Full name').fill(input.name);
  await page.getByRole('textbox', { name: /^Email/ }).fill(input.email);

  if (input.phone !== undefined) {
    await page.getByLabel('Mobile (optional)').fill(input.phone);
  }

  await page.getByRole('button', { name: 'Send the request' }).click();
  await expect(page.getByText('Your request has been received')).toBeVisible({ timeout: 30_000 });

  return true;
}

async function openCard(page: Page, name: string, tab = 'New'): Promise<void> {
  await page.goto('/access-requests');

  if (tab !== 'New') {
    await page.getByRole('tab', { name: new RegExp(`^${tab}`) }).click();
  }

  await page.getByRole('link', { name }).click();
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible({ timeout: 30_000 });
}

test.describe('access requests', () => {
  test('a visitor asks, the team answers, the invited person accepts, and the first sign-in is recorded', async ({
    page,
  }, info) => {
    /* The whole journey, twice signed in and once as a stranger: longer than one screen's budget on a loaded machine. */
    test.setTimeout(120_000);
    const id = stamp(info);
    const name = `Visitor ${id}`;
    const email = `${id}@example.test`.toLowerCase();

    test.skip(!(await ask(page, { name, email })), 'This installation does not take requests.');

    // The team sees it, tries it, and approves it. Approving creates nobody.
    await signIn(page, ACCOUNTS.owner);
    await openCard(page, name);
    await page.getByRole('button', { name: 'Mark WhatsApp contacted' }).click();
    await expect(page.getByText('WhatsApp contacted').first()).toBeVisible();
    await expect(page.getByText(/Email delivery: awaiting credentials/)).toBeVisible();
    await page.getByRole('button', { name: 'Approve' }).click();
    await expect(page.getByRole('region', { name: 'After approval' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'After approval' })).toContainText(
      'Account created—',
    );

    // An invitation is a separate act, shown once; nothing sends it.
    await page.getByRole('button', { name: 'Make the invitation' }).click();
    const link = await page.getByTestId('invitation-link').innerText();

    expect(link).toMatch(/\/invite\/[a-f0-9]{96}$/);
    await expect(page.getByText(/awaiting credentials: send the link yourself/)).toBeVisible();
    await page.getByRole('button', { name: 'I have sent it' }).click();
    await expect(page.getByRole('region', { name: 'After approval' })).not.toContainText(
      'Link handed over (as recorded)—',
    );

    // The invited person opens it with no session, and takes it.
    await page.context().clearCookies();
    await page.goto(link);
    await expect(
      page.getByRole('heading', { level: 1, name: 'Accept your invitation' }),
    ).toBeVisible({
      timeout: 30_000,
    });
    await page.getByLabel(/Choose a password/).fill(PASSWORD);
    await page.getByLabel(/Repeat the password/).fill(PASSWORD);
    await page.getByRole('button', { name: 'Accept and open my account' }).click();
    await expect(page.getByText('Your account is ready')).toBeVisible({ timeout: 30_000 });

    // The link works once.
    await page.goto(link);
    await expect(page.getByText('This invitation is not valid')).toBeVisible({ timeout: 30_000 });

    // Their first sign-in, with the password they chose.
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30_000 });

    // And the team sees all of it, in order.
    await page.context().clearCookies();
    await signIn(page, ACCOUNTS.owner);
    await openCard(page, name, 'Approved');

    const steps = page.getByRole('region', { name: 'After approval' });

    await expect(steps).toContainText('Invitation accepted');
    await expect(steps).not.toContainText('Invitation accepted—');
    await expect(steps).not.toContainText('First sign-in—');
    await expect(steps).not.toContainText('Account created—');
  });

  test('a general enquiry is answered, never approved, and nothing is shown as sent', async ({
    page,
  }, info) => {
    const id = stamp(info);
    const name = `Enquirer ${id}`;

    test.skip(
      !(await ask(page, { path: '/contact', name, email: `${id}@example.test`.toLowerCase() })),
      'This installation does not take requests.',
    );

    await signIn(page, ACCOUNTS.owner);
    await openCard(page, name);

    await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Mark email sent' }).click();
    await expect(page.getByText('Email marked as sent')).toBeVisible();
    await expect(page.getByText(/nothing is sent from here/)).toBeVisible();
  });

  test('a rejection needs a reason, which stays internal', async ({ page }, info) => {
    const id = stamp(info);
    const name = `Rejected ${id}`;

    test.skip(
      !(await ask(page, { name, email: `${id}@example.test`.toLowerCase() })),
      'This installation does not take requests.',
    );

    await signIn(page, ACCOUNTS.owner);
    await openCard(page, name);
    await page.getByRole('button', { name: 'Reject', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Reject the request' })).toBeDisabled();
    await page.getByLabel(/^Reason/).fill('Not a fit for this workspace');
    await page.getByRole('button', { name: 'Reject the request' }).click();
    await expect(page.getByText('Not a fit for this workspace')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText('Internal. Never shown to the person.')).toBeVisible();
  });

  test('a repeat from the same address is kept and marked, and the visitor learns nothing', async ({
    page,
  }, info) => {
    const id = stamp(info);
    const email = `${id}@example.test`.toLowerCase();

    test.skip(
      !(await ask(page, { name: `First ${id}`, email })),
      'This installation does not take requests.',
    );
    // The same page, the same words: a stranger cannot tell this from the first.
    expect(await ask(page, { name: `Second ${id}`, email })).toBe(true);

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/access-requests');

    const second = page.getByRole('listitem').filter({ hasText: `Second ${id}` });

    await expect(second).toBeVisible({ timeout: 30_000 });
    await expect(second.getByText('Possible duplicate')).toBeVisible();
    await expect(page.getByRole('listitem').filter({ hasText: `First ${id}` })).toBeVisible();
  });

  test('the form, the inbox, a request and the invitation fit a 375px phone, in English and Arabic', async ({
    page,
  }, info) => {
    test.setTimeout(120_000);
    const id = stamp(info);
    const name = `Phone ${id}`;

    await page.setViewportSize({ width: 375, height: 812 });

    test.skip(
      !(await ask(page, { name, email: `${id}@example.test`.toLowerCase() })),
      'No intake here.',
    );

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/access-requests');
    await expect(page.getByRole('link', { name })).toBeVisible({ timeout: 30_000 });
    await expectNoSidewaysScroll(page);
    await expectNoViolations(page, 'the inbox');

    await openCard(page, name);
    await expectNoSidewaysScroll(page);
    await expectNoViolations(page, 'the request');
    await page.getByRole('button', { name: 'Approve' }).click();
    await page.getByRole('button', { name: 'Make the invitation' }).click();
    const link = await page.getByTestId('invitation-link').innerText();

    await expectNoSidewaysScroll(page);
    await page.context().clearCookies();
    await page.goto(link);
    await expect(
      page.getByRole('heading', { level: 1, name: 'Accept your invitation' }),
    ).toBeVisible({
      timeout: 30_000,
    });
    await expectNoSidewaysScroll(page);

    // Last, because it leaves a language behind in this browser's storage.
    for (const locale of ['en', 'ar'] as const) {
      const fresh = await page.context().newPage();

      await setLocale(fresh, locale);
      await fresh.setViewportSize({ width: 375, height: 812 });
      await fresh.goto('/request-access');
      await expect(fresh.locator('h1')).toBeVisible({ timeout: 30_000 });
      await expectNoSidewaysScroll(fresh);
      expect(await fresh.evaluate(() => document.documentElement.dir)).toBe(
        locale === 'ar' ? 'rtl' : 'ltr',
      );

      const { violations } = await new AxeBuilder({ page: fresh })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

      expect(
        violations.map((v) => v.id),
        `axe on the request form (${locale})`,
      ).toEqual([]);
      await fresh.close();
    }
  });
});
