import { expect, test } from '@playwright/test';
import { ACCOUNTS, signIn } from './support/accounts';
import { expectNoSidewaysScroll } from './support/reach';

/**
 * The operations view over real traffic: a link bound to a campaign, a lead from a tracked form, and the view that reads them
 * together. What is asserted: the lead is counted, a cost is either a figure or a stated absence (never zero), the team table
 * names no one's detail, and the page fits a phone.
 */
test.describe('tracking operations', () => {
  test('a tracked lead appears in the operations view with its cost stated or withheld', async ({
    page,
    request,
  }, info) => {
    const stamp = `${info.project.name} r${String(info.retry)} ${String(Date.now())}`;
    const linkName = `E2E ops link ${stamp}`;

    await signIn(page, ACCOUNTS.owner);
    await page.goto('/tracking?tab=operations');

    const seeded = await page.evaluate(
      async ([name, formName]) => {
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
        const get = async <T>(url: string): Promise<T> =>
          (await (await fetch(url, { credentials: 'include', headers })).json()) as T;
        const post = async <T>(url: string, body: unknown): Promise<T> =>
          (await (
            await fetch(url, {
              method: 'POST',
              credentials: 'include',
              headers,
              body: JSON.stringify(body),
            })
          ).json()) as T;

        const projects = await get<{ data: { id: string }[] }>(
          '/api/projects?search=Always&per_page=1',
        );
        const project = projects.data[0]?.id ?? '';
        const accounts = await get<{ data: { id: string }[] }>(
          `/api/tracking/binding-options?project_id=${project}&level=accounts`,
        );
        const campaigns = await get<{ data: { id: string }[] }>(
          `/api/tracking/binding-options?project_id=${project}&level=campaigns&parent_id=${accounts.data[0]?.id ?? ''}`,
        );
        const link = await post<{ data: { short_url: string } }>('/api/tracking/links', {
          project_id: project,
          name,
          destination_url: 'https://shop.example.com/ops',
          campaign_id: campaigns.data[0]?.id,
        });
        const form = await post<{ data: { id: string; public_url_token: string } }>(
          '/api/tracking/forms',
          {
            project_id: project,
            name: formName,
            creates_lead: true,
            fields: [
              { key: 'name', type: 'text', label_en: 'Name', required: true, maps_to: 'name' },
              { key: 'phone', type: 'phone', label_en: 'Phone', required: true, maps_to: 'phone' },
            ],
          },
        );
        await post(`/api/tracking/forms/${form.data.id}/status`, { status: 'published' });

        return { short: link.data.short_url, token: form.data.public_url_token };
      },
      [linkName, `E2E ops form ${stamp}`] as const,
    );

    const followed = await request.get(seeded.short.replace(/^https?:\/\/[^/]+/, ''), {
      maxRedirects: 0,
      headers: {
        'user-agent':
          'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
      },
    });
    expect(followed.status()).toBe(302);
    const touchpoint =
      new URL(followed.headers()['location'] ?? '').searchParams.get('ds_tp') ?? '';
    const submitted = await request.post(`/api/public/forms/${seeded.token}/submit`, {
      data: {
        event_id: `e2e-ops-${String(Date.now())}`,
        touchpoint,
        consent: 'granted',
        values: { name: `Ops ${stamp}`, phone: `0555${String(Date.now()).slice(-6)}` },
      },
    });
    expect(submitted.status()).toBe(201);

    await page.reload();
    await page.getByRole('combobox', { name: 'Project' }).fill('Always');
    await page.getByRole('listbox', { name: 'Project' }).getByRole('option').first().click();

    await expect(page.getByText('Tracked leads')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('list', { name: 'From click to sale' })).toBeVisible();
    const row = page.getByRole('row').filter({ hasText: linkName });
    await expect(row).toBeVisible();
    await expect(row.getByText(/Direct|Allocated|Not available/)).toBeVisible();
    await expect(page.getByText('Five readings of one pipeline')).toBeVisible();
    await expect(page.getByText(`Ops ${stamp}`)).toHaveCount(0);
    await expectNoSidewaysScroll(page);
  });
});
