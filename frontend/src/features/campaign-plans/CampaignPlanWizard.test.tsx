import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '@/test/render';
import { CampaignPlanWizard } from './CampaignPlanWizard';
import type { Plan } from './api';

const state = vi.hoisted(() => ({
  planId: undefined as string | undefined,
  plan: null as Plan | null,
  puts: [] as unknown[],
  posts: [] as Array<{ url: string; body: unknown }>,
  navigated: [] as string[],
}));

vi.mock('react-router', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('react-router');

  return {
    ...actual,
    useParams: () => ({ planId: state.planId }),
    useNavigate: () => (to: string) => state.navigated.push(to),
  };
});

vi.mock('@/features/auth', async () => {
  const actual = await vi.importActual<Record<string, unknown>>('@/features/auth');

  return { ...actual, useSession: () => ({ user: { permissions: [] }, can: () => true }) };
});

vi.mock('@/features/projects', () => ({
  useProjectsQuery: () => ({
    data: { data: [{ id: 'p1', name: 'Always-on', client: { name: 'Alhambra' } }] },
  }),
}));

const PLAN: Plan = {
  id: 'plan-1',
  client: { id: 'c1', name: 'Alhambra' },
  project: { id: 'p1', name: 'Always-on' },
  name: 'Ramadan Sale',
  objective: 'leads',
  budget_type: 'daily',
  budget_minor: 50000,
  currency: 'SAR',
  starts_at: '2026-12-01T00:00:00+00:00',
  ends_at: null,
  targets: [
    { external_account_id: 'a1', provider: 'tiktok', name: 'SANDBOX — TikTok', currency: 'SAR' },
  ],
  tracking_link: null,
  landing_url: 'https://example.test',
  destination: 'https://example.test',
  conversion: null,
  targeting: {
    countries: ['SA'],
    age_min: 18,
    age_max: 54,
    gender: 'all',
    languages: ['ar'],
    interests: null,
  },
  placement: { mode: 'automatic', placements: [] },
  ad_copy: { headline: 'Up to 40% off', primary_text: null, call_to_action: null },
  creatives: [],
  status: 'ready_for_review',
  editable: true,
  version: 2,
  status_reason: null,
  created_by: 'Sara',
  submitted_by: 'Sara',
  submitted_at: '2026-10-24T10:00:00+00:00',
  updated_at: '2026-10-24T10:00:00+00:00',
  missing: [],
};

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    ensureCsrfCookie: () => Promise.resolve(),
    api: {
      get: (url: string) => {
        if (url.endsWith('/translation')) {
          return Promise.resolve({
            data: {
              data: [
                {
                  provider: 'tiktok',
                  objective: 'LEAD_GENERATION',
                  daily_budget_minor: 50000,
                  currency: 'SAR',
                  creates: 'unverified',
                  fields: [{ field: 'goal', support: 'supported', note: null }],
                  paused_on_create: 'operation_status=DISABLE',
                  delivers_by_default: true,
                  access: 'app review',
                },
              ],
            },
          });
        }
        if (url.endsWith('/options')) {
          return Promise.resolve({
            data: { data: { accounts: [], tracking_links: [], conversions: [], creatives: [] } },
          });
        }
        return Promise.resolve({ data: { data: state.plan } });
      },
      put: (_url: string, body: unknown) => {
        state.puts.push(body);
        return Promise.resolve({ data: { data: state.plan } });
      },
      post: (url: string, body: unknown) => {
        state.posts.push({ url, body });
        return Promise.resolve({
          data: { data: { ...PLAN, id: 'new-plan', status: 'draft', version: 1 } },
        });
      },
    },
  };
});

describe('CampaignPlanWizard', () => {
  beforeEach(() => {
    state.planId = undefined;
    state.plan = PLAN;
    state.puts = [];
    state.posts = [];
    state.navigated = [];
  });

  it('keeps a new plan from its first step, and opens it at its own address', async () => {
    const user = userEvent.setup();
    await renderWithProviders(<CampaignPlanWizard />);

    expect(screen.getByRole('button', { name: /Platforms & accounts/ })).toBeDisabled();
    await user.selectOptions(screen.getByLabelText('Project'), 'p1');
    await user.type(screen.getByLabelText('Campaign name'), 'Eid push');
    await user.click(screen.getByRole('button', { name: 'Next' }));

    expect(state.posts[0]?.url).toBe('/campaign-plans');
    expect(state.posts[0]?.body).toMatchObject({
      project_id: 'p1',
      name: 'Eid push',
      budget_minor: 50000,
      currency: 'SAR',
    });
    expect(state.navigated).toEqual(['/campaigns/plans/new-plan']);
  });

  it('reads a plan under review step by step without saving it, and warns where a platform delivers by default', async () => {
    const user = userEvent.setup();
    state.planId = 'plan-1';
    await renderWithProviders(<CampaignPlanWizard />);

    expect(await screen.findByText('Ready for review')).toBeInTheDocument();
    for (let i = 0; i < 9; i++) {
      await user.click(screen.getByRole('button', { name: 'Next' }));
    }

    expect(state.puts).toEqual([]);
    expect(await screen.findByRole('article', { name: 'TikTok Ads' })).toHaveTextContent(
      'LEAD_GENERATION',
    );
    expect(
      screen.getByText(/starts new campaigns delivering unless told otherwise/),
    ).toBeInTheDocument();
    expect(screen.getByText('operation_status=DISABLE')).toBeInTheDocument();
  });
});
