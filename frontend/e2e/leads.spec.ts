import { expect, test, type Page } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';

/**
 * Sign in and open the Lead Center.
 *
 * Per test rather than in a `beforeEach`, because one test needs a different account: signing in
 * twice in one browser context leaves a session the second attempt cannot replace.
 */
async function openLeads(page: Page, account: string = ACCOUNTS.owner): Promise<void> {
  await signIn(page, account);
  await page.goto('/leads');
}

test.describe('lead centre', () => {
  test('shows the pipeline and the counted taps without a rate between them', async ({ page }) => {
    await openLeads(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Lead Center' })).toBeVisible({
      timeout: 30_000,
    });

    const signals = page.getByRole('region', { name: 'Taps and forms begun' });
    await expect(signals).toBeVisible();

    /*
     * The refusal this surface exists for, asserted in the browser as well as in the unit tests:
     * taps and leads appear together and nothing divides one by the other. An agency quoting a
     * "tap-to-lead rate" would be quoting a figure with no denominator anybody can defend.
     */
    await expect(signals.getByRole('alert')).toContainText('no percentage is offered');
    await expect(signals.getByText('%')).toHaveCount(0);
  });

  test('explains why qualified is not a step in the funnel', async ({ page }) => {
    await openLeads(page);

    const pipeline = page.getByRole('region', { name: 'How far leads got' });
    await expect(pipeline).toBeVisible({ timeout: 30_000 });

    // The reason lives beside the chart, because the reader is who it protects.
    await expect(pipeline).toContainText('Qualified is not a step here');
  });

  test('offers every chart as a table', async ({ page }) => {
    await openLeads(page);

    const pipeline = page.getByRole('region', { name: 'How far leads got' });
    await expect(pipeline).toBeVisible({ timeout: 30_000 });

    /*
     * A canvas tells a screen reader nothing and a tooltip needs a pointer, so the table is the
     * only route to a value for some readers — and it is in the open rather than behind a
     * setting, because plenty of readers simply prefer it.
     */
    await pipeline.getByRole('button', { name: 'Table', exact: true }).click();

    await expect(pipeline.getByRole('row', { name: /Arrived/ })).toBeVisible();
    await expect(pipeline.getByRole('columnheader', { name: 'As a share of' })).toBeVisible();
  });

  test('says where a CRM-sourced lead\u2019s stage is moved, instead of offering the controls', async ({
    page,
  }) => {
    await openLeads(page);

    const table = page.getByRole('table', { name: /Leads, with their route/ });
    await expect(table).toBeVisible({ timeout: 30_000 });

    /*
     * The demo reads a sandbox CRM, so the pipeline carries leads the CRM owns. A move made here would be undone
     * by the next sync without anybody being told, so the sentence goes where the controls would have been.
     */
    const owned = table.getByText(/which owns this stage/).first();
    await expect(owned).toBeVisible();
    await expect(owned).toContainText('HubSpot');

    /*
     * And the case the whole bridge exists for, visible in the demo: the fixture's pipeline has a stage labelled
     * "Won" that is **not** marked closed, so the lead behind it sits at "new" with "Won" beside it. Confusing
     * until somebody reads why, and the alternative is reporting a sale that has not happened.
     */
    await expect(table.getByText(/HubSpot · Won/).first()).toBeVisible();
  });

  /*
   * The merge, read-only here on purpose. The suite shares one seeded database between two browser projects, so
   * carrying a merge out would remove a lead the other project's tests are reading — and a merge has no undo.
   * What is asserted is everything up to the irreversible click: that the suggestion appears, that it names the
   * contact point it matched on, that the consequence is stated, and that the surviving stage is asked for and
   * offers only the two stages the two leads are in.
   */
  test('offers to merge two enquiries from one phone number, and says what that will do', async ({
    page,
  }) => {
    await openLeads(page);

    const table = page.getByRole('table', { name: /Leads, with their route/ });
    await expect(table).toBeVisible({ timeout: 30_000 });

    /* The demo seeds one person who enquired twice, eighteen days apart, through two different routes. */
    await table.getByRole('button', { name: 'Mohammad Alharbi' }).click();

    const panel = page.getByText('Is this the same person?');
    await expect(panel).toBeVisible({ timeout: 20_000 });

    /*
     * The evidence, on the row somebody is about to act on. The two records' names are spelled differently —
     * deliberately, in the seed — and the match is on the number, which is what the label says.
     */
    await expect(page.getByText('Same phone number').first()).toBeVisible();

    await page.getByRole('button', { name: 'Merge with this' }).first().click();

    await expect(page.getByText(/earlier arrival time is kept/)).toBeVisible();
    await expect(page.getByText(/there is no undo/)).toBeVisible();

    const stages = page.getByRole('group', { name: /Which stage does the merged lead stay in/ });
    await expect(stages).toBeVisible();

    /* Until a stage is chosen there is nothing to send, and the control says so by being unavailable. */
    await expect(page.getByRole('button', { name: 'Merge these leads' })).toBeDisabled();

    /* Both leads are new, so one stage is offered rather than two spelling the same thing twice. */
    await expect(stages.getByRole('button', { name: 'New' })).toBeVisible();
    await expect(stages.getByRole('button', { name: 'Qualified' })).toHaveCount(0);
  });

  /*
   * Ownership, value and a note — the three things the pipeline had columns for and nothing could set. The owner
   * list is the assertion that matters: it is served by the server and holds only colleagues who can open this
   * client's leads, so a name in it is a name the server will accept.
   */
  test('works a lead: owner, value and what happened', async ({ page }) => {
    await openLeads(page);

    const table = page.getByRole('table', { name: /Leads, with their route/ });
    await expect(table).toBeVisible({ timeout: 30_000 });

    /* Run-unique, because the suite shares one seeded database between two browser projects. */
    const note = `Rang twice, no answer. ${Date.now()}`;

    await table.getByRole('button', { name: 'Ghada Al-Amri' }).click();

    const panel = page.getByText('Working this lead');
    await expect(panel).toBeVisible({ timeout: 20_000 });

    const owner = page.getByRole('combobox', { name: 'Owner' });
    await expect(owner).toBeVisible();

    /*
     * "Nobody yet" is an option rather than the absence of one. A pipeline that can only ever add an owner
     * accumulates leads belonging to whoever touched them first.
     */
    await expect(owner.getByRole('option', { name: 'Nobody yet' })).toHaveCount(1);

    /*
     * A named option with a retrying matcher, rather than counting: the list is fetched per lead, so a count
     * taken the moment the panel appears races the request and reads only "Nobody yet".
     *
     * The demo's project manager holds `projects.view_all`, so they can open every client's leads and must be
     * offered. The media buyer, assigned to one project, is the one this list exists to filter.
     */
    await expect(owner.getByRole('option', { name: 'Demo Manager' })).toHaveCount(1);

    await page.getByRole('textbox', { name: 'What happened' }).fill(note);
    await page.getByRole('button', { name: 'Add to the history' }).click();

    /* It lands in the lead's own history, which is the panel beside this one. */
    await expect(page.getByText(note)).toBeVisible({ timeout: 20_000 });
  });

  /*
   * The CRM read, which until now had an endpoint and no way to reach it. Read-only here: running it would write
   * leads into the database the other browser project's tests are reading.
   */
  test('offers a CRM read, and asks which client the deals belong to', async ({ page }) => {
    await openLeads(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Lead Center' })).toBeVisible({
      timeout: 30_000,
    });

    /* The demo connects HubSpot on sandbox credentials, so the panel is present and names it. */
    const panel = page.getByText('Deals in HubSpot');
    await expect(panel).toBeVisible();

    /*
     * No client chosen yet, so there is nothing to read: a CRM portal belongs to one company and a workspace has
     * several clients, and nothing here infers which deals are whose.
     */
    await expect(page.getByText(/Choose a client above first/)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Read the deals' })).toHaveCount(0);
  });

  test('keeps the Lead Center away from a role without the permission', async ({ page }) => {
    await openLeads(page, ACCOUNTS.creative);

    /*
     * The surface carries prospects' names and phone numbers. A creative has no business with
     * them, and the page says so rather than rendering empty — a blank page reads as broken and
     * invites somebody to ask an engineer about it.
     */
    await expect(page.getByText('The Lead Center is not part of your access')).toBeVisible({
      timeout: 30_000,
    });
  });

  test('reads in Arabic, right to left', async ({ page }) => {
    await openLeads(page, ACCOUNTS.manager);

    await expect(page.getByRole('heading', { level: 1, name: 'Lead Center' })).toBeVisible({
      timeout: 30_000,
    });

    await page.getByRole('button', { name: 'Switch language' }).click();

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(
      page.getByRole('heading', { level: 1, name: 'مركز العملاء المحتملين' }),
    ).toBeVisible();

    /*
     * The caveats are translated too, not just the chrome. A chart whose warnings are in English
     * on an Arabic page is a chart whose warnings go unread, which is worse than not having them.
     */
    await expect(page.getByText('«مؤهَّل» ليست مرحلة هنا')).toBeVisible();
  });
});
