import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { OperationsTab } from './OperationsTab';
import type { Operations } from './types';

const state: { data: Operations } = { data: undefined as unknown as Operations };

vi.mock('./api', () => ({
  useOperationsQuery: () => ({ isPending: false, isError: false, data: state.data }),
}));
vi.mock('../links/api', () => ({ useLinksQuery: () => ({ data: [] }) }));
vi.mock('../health/ProjectScope', () => ({
  ProjectScope: ({ onChange }: { onChange: (v: { id: string; label: string }) => void }) => (
    <button type="button" onClick={() => onChange({ id: 'p1', label: 'Spring' })}>
      pick project
    </button>
  ),
}));

const none = { minor: null, currency: null, reason: 'No spend can be credited to this link.' };

function ops(over: Partial<Operations> = {}): Operations {
  return {
    from: '2026-09-24',
    to: '2026-10-23',
    timezone: 'Asia/Riyadh',
    sla_minutes: null,
    links_considered: 1,
    links_total: 1,
    truncated: false,
    kpis: {
      tracked: { clicks: 40, leads: 5, qualified: 2, appointments: 1, sales: 0 },
      by_currency: [],
      unpriced_links: 1,
      response: {
        sla_minutes: null,
        leads: 5,
        median_attempt_seconds: null,
        median_contact_seconds: null,
        sla_compliance: null,
        overdue: null,
      },
    },
    funnel: {
      scope: 'project',
      stages: [
        {
          source: 'link_click',
          count: 40,
          conversion_from_previous: null,
          drop_off: null,
          median_seconds_from_previous: null,
        },
        {
          source: 'landing_view',
          count: null,
          conversion_from_previous: null,
          drop_off: null,
          median_seconds_from_previous: null,
          reason: 'The destination page has not reported this step.',
        },
      ],
    },
    links: [
      {
        link: { id: 'l1', name: 'Poster' },
        platform: 'meta',
        campaign: 'Spring',
        ad_group: null,
        ad: null,
        bound: true,
        spend: {
          basis: 'UNAVAILABLE',
          minor: null,
          currency: null,
          reason: 'Shared with another link.',
          level: 'campaign',
        },
        clicks: 40,
        leads: 5,
        qualified: 2,
        appointments: null,
        sales: null,
        cpl: none,
        cpql: none,
        cac: none,
        revenue: {},
        sla: null,
        tracking: 'page_not_reporting',
      },
    ],
    team: [],
    attribution: {
      platform_reported: { clicks: null, results: null, entities: 0, note: '' },
      first_known_touch: {
        leads_with_a_known_first_touch: 0,
        same_as_last: 0,
        different_from_last: 0,
      },
      last_known_touch: { leads: 5 },
      digitalsmart_attributed: { leads: 5 },
      outcome: { sales: 0, recorded_won_value_by_currency: {} },
      coverage: 1,
      confidence: 'high',
      missing_evidence: 0,
    },
    attention: [],
    ...over,
  };
}

async function open() {
  await renderWithProviders(<OperationsTab />);
  (await screen.findByRole('button', { name: 'pick project' })).click();
}

describe('OperationsTab', () => {
  it('shows no cost when no link has spend of its own, and says why instead of showing zero', async () => {
    state.data = ops();
    await open();

    expect(
      await screen.findByText(/No link has spend that can be credited to it alone/),
    ).toBeInTheDocument();
    expect(screen.queryByText('Cost per lead', { selector: 'dt' })).not.toBeInTheDocument();
    expect(screen.getByText('Not available')).toBeInTheDocument();
    expect(screen.getAllByText('Not reported').length).toBeGreaterThan(2);
  });

  it('writes a step the page never reported as not reported, with its reason', async () => {
    state.data = ops();
    await open();

    expect(
      await screen.findByText('The destination page has not reported this step.'),
    ).toBeInTheDocument();
    expect(screen.getByText('Page not reporting')).toBeInTheDocument();
  });

  it('shows cost figures per currency, over the priced links only', async () => {
    state.data = ops({
      kpis: {
        ...ops().kpis,
        by_currency: [
          {
            currency: 'SAR',
            spend_minor: 100_000,
            priced_links: 1,
            leads: 4,
            qualified: 2,
            appointments: 1,
            sales: 0,
            cpl_minor: 25_000,
            cpql_minor: 50_000,
            cac_minor: null,
            revenue: { lead_value_minor: null, store_attributed_minor: null },
            roas: { lead_value: null, store_attributed: null },
          },
        ],
      },
    });
    await open();

    expect(await screen.findByText(/Costs in SAR are over the 1 link/)).toBeInTheDocument();
    expect(screen.getAllByText('Cost per sale').length).toBeGreaterThan(0);
    expect(screen.getByText('No sales yet.')).toBeInTheDocument();
  });

  it('lists an observation with the figures behind it, and says so when there is nothing to say', async () => {
    state.data = ops({
      attention: [
        {
          code: 'spend_no_leads',
          severity: 'attention',
          subject: 'Poster',
          evidence: { clicks: 40, leads: 0, link_id: 'l1' },
        },
      ],
    });
    await open();

    expect(await screen.findByText('Poster: spend and clicks, but no leads')).toBeInTheDocument();
    expect(screen.getByText('clicks: 40 · leads: 0')).toBeInTheDocument();
  });
});
