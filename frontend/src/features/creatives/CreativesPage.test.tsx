import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { MetricValue } from '@/shared/metrics';
import { renderWithProviders } from '@/test/render';
import { CreativesPage } from './CreativesPage';
import type { CreativePayload, CreativeRow } from './types';

const calls: string[] = [];
let respond: () => Promise<unknown>;

vi.mock('@/features/clients', () => ({
  useClientsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));
vi.mock('@/features/projects', () => ({
  useProjectsQuery: () => ({ data: { data: [] }, isFetching: false }),
}));
vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();

  return {
    ...original,
    api: {
      get: (url: string) => {
        calls.push(url);

        return respond();
      },
    },
  };
});

const value = (
  metric: string,
  number: number | null,
  format: MetricValue['format'] = 'count',
): MetricValue => ({
  metric,
  label: metric.toUpperCase(),
  value: number,
  format,
  precision: 0,
  is_minor_units: false,
  currency: null,
  source: 'synced',
  source_label: 'Synced',
  requires_label: false,
  unavailable_reason: number === null ? 'Not reported by the platform.' : null,
  comparison_value: null,
  change_ratio: null,
  change_is_good: null,
  lower_is_better: false,
});

function row(key: string, label: string, over: Partial<CreativeRow> = {}): CreativeRow {
  return {
    key,
    label,
    platform: 'meta',
    platform_label: 'Meta',
    format: 'image',
    format_label: 'Image',
    media: {
      state: 'available',
      kind: 'image',
      url: 'https://cdn.example-real.com/a.jpg',
      origin: 'provider',
    },
    ads: 1,
    campaigns: ['Eid campaign'],
    campaign_count: 1,
    ad_name: null,
    metrics: {
      source: 'synced',
      source_label: 'Synced',
      currency: 'SAR',
      values: {
        spend: value('spend', 1200),
        ctr: value('ctr', 2.1, 'percentage'),
        cpa: value('cpa', 40),
        frequency: value('frequency', null),
      },
    },
    trend: [
      { date: '2026-10-14', spend: 100, ctr: 2.4 },
      { date: '2026-10-15', spend: 110, ctr: 2.1 },
    ],
    verdict: 'top',
    verdict_label: 'Top',
    evidence: [],
    ...over,
  };
}

const payload = (rows: CreativeRow[], over: Partial<CreativePayload> = {}): CreativePayload => ({
  grain: 'asset',
  range: { start: '2026-09-21', end: '2026-10-20', days: 30 },
  total: rows.length,
  has_more: false,
  facets: {
    formats: [{ key: 'image', label: 'Image', count: rows.length }],
    platforms: [{ key: 'meta', label: 'Meta', count: rows.length }],
  },
  rows,
  ...over,
});

const answer = (data: CreativePayload) => () => Promise.resolve({ data: { data } });

beforeEach(() => {
  calls.length = 0;
  respond = answer(
    payload([
      row('k1', 'Eid film'),
      row('k2', 'Ramadan still', {
        verdict: 'fatiguing',
        format: 'video',
        format_label: 'Video',
        media: { state: 'fixture', kind: 'none', url: null, origin: null },
        evidence: [
          { signal: 'ctr_change', value: -0.32 },
          { signal: 'frequency_change', value: 0.4 },
        ],
      }),
      row('k3', 'Brand', {
        verdict: 'weak',
        media: { state: 'none', kind: 'none', url: null, origin: null },
        platform: 'google_ads',
        platform_label: 'Google Ads',
      }),
    ]),
  );
});

describe('CreativesPage', () => {
  it('counts each view and opens on the top creatives', async () => {
    await renderWithProviders(<CreativesPage />);
    const views = within(await screen.findByRole('group', { name: 'Creative view' }));

    expect(views.getByRole('button', { name: /Top · 1/ })).toBeInTheDocument();
    expect(views.getByRole('button', { name: /Fatiguing · 1/ })).toBeInTheDocument();
    expect(views.getByRole('button', { name: /Needs replacement · 1/ })).toBeInTheDocument();
    expect(screen.getByText('Eid film')).toBeInTheDocument();
    expect(screen.queryByText('Ramadan still')).not.toBeInTheDocument();
  });

  it('draws a real preview with no referrer, and never invents one where there is none', async () => {
    await renderWithProviders(<CreativesPage />);
    const image = await screen.findByRole('img', { name: 'Eid film' });

    expect(image).toHaveAttribute('src', 'https://cdn.example-real.com/a.jpg');
    expect(image).toHaveAttribute('referrerpolicy', 'no-referrer');

    const views = within(screen.getByRole('group', { name: 'Creative view' }));
    await userEvent.click(views.getByRole('button', { name: /Fatiguing/ }));
    expect(await screen.findByText('Sample data — no preview')).toBeInTheDocument();
    expect(screen.queryByRole('img', { name: 'Ramadan still' })).not.toBeInTheDocument();

    await userEvent.click(views.getByRole('button', { name: /Needs replacement/ }));
    expect(await screen.findByText('No preview from the platform')).toBeInTheDocument();
  });

  it('writes the evidence beside a fatigue verdict, and a missing figure as a dash', async () => {
    await renderWithProviders(<CreativesPage />);
    const views = within(await screen.findByRole('group', { name: 'Creative view' }));
    await userEvent.click(views.getByRole('button', { name: /Fatiguing/ }));

    expect(await screen.findByText(/CTR\s*-32%|CTR\s*−32%/)).toBeInTheDocument();
    expect(screen.getByText(/Frequency\s*\+40%/)).toBeInTheDocument();
    // Frequency was not reported: a dash, not 0.
    const card = screen.getByText('Ramadan still').closest('li') as HTMLElement;
    expect(within(card).queryByText('0')).not.toBeInTheDocument();
  });

  it('compares two to four creatives, and not with one', async () => {
    await renderWithProviders(<CreativesPage />);
    await screen.findByText('Eid film');

    await userEvent.click(screen.getByRole('checkbox', { name: /Compare\s*— Eid film/ }));
    expect(screen.getByText('Choose one more to compare.')).toBeInTheDocument();

    const views = within(screen.getByRole('group', { name: 'Creative view' }));
    await userEvent.click(views.getByRole('button', { name: /Fatiguing/ }));
    await userEvent.click(
      await screen.findByRole('checkbox', { name: /Compare\s*— Ramadan still/ }),
    );

    expect(await screen.findByRole('region', { name: 'Comparison' })).toBeInTheDocument();
  });

  it('asks the server for the platform it is narrowed to', async () => {
    await renderWithProviders(<CreativesPage />);
    await screen.findByText('Eid film');

    await userEvent.selectOptions(screen.getByLabelText('Platform'), 'meta');

    expect(calls.some((url) => url.includes('providers%5B%5D=meta'))).toBe(true);
    expect(calls[0]).toContain('preset=30d');
  });

  it('says how many it is showing when the selection is larger than a page', async () => {
    respond = answer(payload([row('k1', 'Eid film')], { has_more: true, total: 640 }));
    await renderWithProviders(<CreativesPage />);

    expect(await screen.findByText('Showing the 1 largest by spend of 640.')).toBeInTheDocument();
  });

  it('shows an empty state, and an error, in words', async () => {
    respond = answer(payload([]));
    const first = await renderWithProviders(<CreativesPage />);
    expect(await screen.findByText('No creatives in this selection')).toBeInTheDocument();
    first.unmount();

    respond = () => Promise.reject(new Error('boom'));
    await renderWithProviders(<CreativesPage />);
    expect(await screen.findByText(/could not be loaded/)).toBeInTheDocument();
  });

  it('prints no provider identifier or token', async () => {
    const { container } = await renderWithProviders(<CreativesPage />);
    await screen.findByText('Eid film');

    expect(container.textContent).not.toMatch(/external|token|act_\d/i);
  });
});
