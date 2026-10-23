import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * A new lead is handed out, and shows up in the queue against its response time.
 *
 * The project's rule is set in the interface; a visitor then submits a published form; the queue must show the lead with its
 * owner and a response-time state. The rule is switched off again at the end so the demo project is left as it was found.
 */
test.describe('lead follow-up', () => {
  test('a new lead is assigned by the project rule and queued against its response time', async ({
    page,
    request,
  }, info) => {
    const stamp = `${info.project.name} r${String(info.retry)} ${String(Date.now())}`;

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/tracking?tab=forms');

    const projectName = `E2E queue project ${stamp}`;
    const token = await page.evaluate(
      async ([formName, projectName]) => {
        const xsrf = decodeURIComponent(
          document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
        );
        const headers = {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrf,
        };
        const clients = (await (
          await fetch('/api/clients?per_page=1', { credentials: 'include', headers })
        ).json()) as { data: { id: string }[] };
        /* A project of its own, so the two browser projects never switch each other's rule on and off. */
        const project = (await (
          await fetch('/api/projects', {
            method: 'POST',
            credentials: 'include',
            headers,
            body: JSON.stringify({
              client_id: clients.data[0]?.id,
              name: projectName,
              currency: 'SAR',
            }),
          })
        ).json()) as { data: { id: string } };
        const form = (await (
          await fetch('/api/tracking/forms', {
            method: 'POST',
            credentials: 'include',
            headers,
            body: JSON.stringify({
              project_id: project.data.id,
              name: formName,
              creates_lead: true,
              fields: [
                { key: 'name', type: 'text', label_en: 'Name', required: true, maps_to: 'name' },
                {
                  key: 'phone',
                  type: 'phone',
                  label_en: 'Phone',
                  required: true,
                  maps_to: 'phone',
                },
              ],
            }),
          })
        ).json()) as { data: { id: string; public_url_token: string } };
        await fetch(`/api/tracking/forms/${form.data.id}/status`, {
          method: 'POST',
          credentials: 'include',
          headers,
          body: JSON.stringify({ status: 'published' }),
        });

        return form.data.public_url_token;
      },
      [`E2E queue form ${stamp}`, projectName] as const,
    );

    await page.goto('/tracking?tab=followup');
    await page.getByRole('combobox', { name: 'Project' }).fill(projectName);
    await page.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();
    await expect(page.getByRole('heading', { name: 'New leads' })).toBeVisible({ timeout: 30_000 });

    await page.getByLabel('Handle new leads automatically').check();
    await page.getByLabel('Give each new lead to').selectOption('round_robin');
    await page.getByRole('button', { name: '30 min' }).click();
    await page.getByLabel('Open a task for each new lead').check();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Saved.')).toBeVisible({ timeout: 30_000 });
    await expectNoSidewaysScroll(page);

    const phone = `0555${String(Date.now()).slice(-6)}`;
    const submitted = await request.post(`/api/public/forms/${token}/submit`, {
      data: {
        event_id: `e2e-queue-${String(Date.now())}`,
        consent: 'granted',
        values: { name: `Queue ${stamp}`, phone },
      },
    });
    expect(submitted.status()).toBe(201);

    await page.reload();
    await page.getByRole('combobox', { name: 'Project' }).fill(projectName);
    await page.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();

    const row = page.getByRole('listitem').filter({ hasText: `Queue ${stamp}` });
    await expect(row).toBeVisible({ timeout: 30_000 });
    await expect(row.getByText('Within time')).toBeVisible();
    await expect(row.getByText(/With /)).toBeVisible();
    await expect(row.getByText(/rota/)).toBeVisible();
    await expectNoSidewaysScroll(page);

    await page.getByLabel('Handle new leads automatically').uncheck();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Saved.')).toBeVisible({ timeout: 30_000 });
  });
});
