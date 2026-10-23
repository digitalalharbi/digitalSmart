import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '@/test/render';
import { LinksTab } from './LinksTab';
import type { TrackingLink } from './types';

const links: { data: TrackingLink[] | undefined } = { data: undefined };

vi.mock('./api', () => ({
  useLinksQuery: () => ({ isPending: false, isError: false, data: links.data }),
  useSetLinkStatusMutation: () => ({ isPending: false, mutate: vi.fn() }),
  useSaveLinkMutation: () => ({
    isPending: false,
    isError: false,
    mutate: vi.fn(),
    reset: vi.fn(),
  }),
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
});
