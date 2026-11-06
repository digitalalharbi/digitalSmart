import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { LinksTab } from './LinksTab';
import type { LinkAudit, TrackingLink } from './types';

const links: { data: TrackingLink[] | undefined } = { data: undefined };
const audit: { data: LinkAudit | undefined } = { data: undefined };

vi.mock('./api', () => ({
  useLinksQuery: () => ({ isPending: false, isError: false, data: links.data }),
  useLinkAuditQuery: () => ({ data: audit.data }),
  useBindingOptions: () => ({ data: [], isFetching: false }),
  useLinkPerformanceQuery: () => ({ isPending: true, isError: false, data: undefined }),
  useSetLinkStatusMutation: () => ({ isPending: false, mutate: vi.fn() }),
  useSaveLinkMutation: () => ({
    isPending: false,
    isError: false,
    mutate: vi.fn(),
    reset: vi.fn(),
  }),
}));
vi.mock('./influencers', () => ({
  useInfluencersQuery: () => ({ isError: false, data: [] }),
  useSaveInfluencerMutation: () => ({ isPending: false, mutate: vi.fn(), error: null }),
}));
vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));
vi.mock('@/features/projects', () => ({
  useProjectsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));
vi.mock('@/features/auth', () => ({
  PERMISSIONS: { trackingManage: 'tracking.manage' },
  useSession: () => ({ can: () => true }),
}));

function link(overrides: Partial<TrackingLink> = {}): TrackingLink {
  return {
    id: 'l1',
    client: { id: 'c1', name: 'Alhambra' },
    project: { id: 'p1', name: 'Spring' },
    name: 'Snap spring offer',
    short_url: 'https://app.example.com/api/r/AbCdEfGhIj',
    platform: 'snapchat',
    campaign: 'Spring',
    ad_group: null,
    ad: null,
    creative: null,
    binding: {
      external_account_id: null,
      campaign_id: null,
      ad_group_id: null,
      ad_id: null,
      creative_id: null,
      level: null,
    },
    conversion_goal: null,
    influencer: null,
    channel: 'paid',
    destination_url: 'https://shop.example.com',
    utm: {},
    custom_params: {},
    objective: null,
    status: 'active',
    expires_at: null,
    notes: null,
    created_at: '2026-11-01T10:00:00Z',
    counts: {
      clicks: { value: 12, state: 'live' },
      visitors: { value: 10, state: 'live' },
      sessions: { value: 12, state: 'live' },
      landing_views: {
        value: null,
        state: 'partial',
        reason: 'The destination page has not reported.',
      },
      click_id_coverage: null,
      excluded: 2,
      last_click_at: null,
    },
    ...overrides,
  };
}

describe('LinksTab', () => {
  beforeEach(() => {
    links.data = [link()];
    audit.data = undefined;
  });

  it('shows a figure it cannot know as not reported with its reason, never as zero', async () => {
    await renderWithProviders(<LinksTab />);

    expect(screen.getByText('The destination page has not reported.')).toBeInTheDocument();
    expect(screen.getAllByText('Not reported').length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText('12')).toBeInTheDocument();
    expect(screen.getByText(/2 visits were marked as likely automated/)).toBeInTheDocument();
  });

  it('shows the short address left to right and offers to create a link', async () => {
    await renderWithProviders(<LinksTab />);

    expect(screen.getByText('https://app.example.com/api/r/AbCdEfGhIj')).toHaveAttribute(
      'dir',
      'ltr',
    );
    expect(screen.getByRole('button', { name: 'New tracking link' })).toBeInTheDocument();
  });

  it('says so when there are no links', async () => {
    links.data = [];
    await renderWithProviders(<LinksTab />);

    expect(screen.getByText('No tracking links yet')).toBeInTheDocument();
  });

  it('warns about campaign-parameter issues, naming the link and what is wrong', async () => {
    links.data = [link()];
    audit.data = {
      audited: 3,
      with_findings: 1,
      by_code: { missing_medium: 1, duplicate_set: 1 },
      links: [
        {
          id: 'l1',
          name: 'Snap spring offer',
          project_id: 'p1',
          project_name: 'Spring',
          client_name: 'Alhambra',
          status: 'active',
          findings: [
            { code: 'missing_medium', parameters: ['utm_medium'], shared_with: [] },
            { code: 'duplicate_set', parameters: [], shared_with: ['l2', 'l3'] },
          ],
        },
      ],
    };

    await renderWithProviders(<LinksTab />);

    const panel = screen.getByRole('region', { name: 'Campaign parameter issues' });

    expect(panel).toHaveTextContent('1 of 3 links has a campaign-parameter issue');
    expect(panel).toHaveTextContent('no utm_medium');
    expect(panel).toHaveTextContent('the same parameters as 2 other live links');
  });

  it('says nothing about parameters when the audit found nothing', async () => {
    links.data = [link()];
    audit.data = { audited: 1, with_findings: 0, by_code: {}, links: [] };

    await renderWithProviders(<LinksTab />);

    expect(
      screen.queryByRole('region', { name: 'Campaign parameter issues' }),
    ).not.toBeInTheDocument();
  });
});

describe('LinksTab — influencer on a link', () => {
  it('names the influencer a link was made for beside its client and project', async () => {
    links.data = [link({ influencer: { id: 'i1', name: 'Noura Alharbi' } })];
    await renderWithProviders(<LinksTab />);

    expect(screen.getByText(/Alhambra · Spring · Noura Alharbi/)).toBeInTheDocument();
  });
});
