import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { ReportSectionView } from './ReportSectionView';
import type { LeadAggregates, RenderedSection } from './types';

const base: LeadAggregates = {
  total: 20,
  reached: 12,
  qualified: 6,
  disqualified: 2,
  won: 3,
  lost: 1,
  open: 14,
  qualification_rate: 50,
  win_rate: 50,
  stages: { new: 5, contacted: 3, qualified: 6, disqualified: 2, won: 3, lost: 1 },
  sources: [
    {
      kind: 'form_submission',
      label: 'Form submission',
      total: 12,
      qualified: 5,
      is_measured: true,
    },
    { kind: 'phone_call', label: 'Phone call', total: 8, qualified: 1, is_measured: true },
  ],
  campaigns: [
    { label: 'Spring', is_unattributed: false, total: 10, qualified: 4, won: 2, closed: 3 },
    { label: null, is_unattributed: true, total: 10, qualified: 2, won: 1, closed: 2 },
  ],
  response: { measured: 12, unanswered: 8, median_seconds: 1500, buckets: [2, 3, 4, 2, 1] },
};

function section(lead: LeadAggregates, empty = false): RenderedSection {
  return {
    kind: 'lead',
    title: 'Leads',
    metrics: [],
    dimension: null,
    dimension_label: null,
    source: 'synced',
    source_label: 'Synced',
    requires_label: false,
    is_empty: empty,
    caveats: ['These are the leads recorded for this client.'],
    currency: null,
    totals: null,
    series: [],
    rows: [],
    lead,
  };
}

describe('LeadSectionView', () => {
  it('shows the counts and the rate, each under its own name', async () => {
    await renderWithProviders(<ReportSectionView section={section(base)} />);

    expect(await screen.findByText('Qualified, of leads reached')).toBeInTheDocument();
    expect(screen.getByText('50%')).toBeInTheDocument();
    expect(screen.getByText('From lead to sale')).toBeInTheDocument();
    expect(screen.getByText('How leads arrived')).toBeInTheDocument();
  });

  it('writes a missing rate and a missing median as not available, never as zero', async () => {
    const none = {
      ...base,
      qualification_rate: null,
      response: { measured: 0, unanswered: 20, median_seconds: null, buckets: [0, 0, 0, 0, 0] },
    };

    await renderWithProviders(<ReportSectionView section={section(none)} />);
    await screen.findByText('Qualified, of leads reached');

    expect(screen.queryByText('0%')).not.toBeInTheDocument();
    // The response chart is withheld entirely rather than drawn as five empty bars.
    expect(screen.queryByText('Time to first contact')).not.toBeInTheDocument();
  });

  it('does not list the unattributed row as a campaign, and says so', async () => {
    await renderWithProviders(<ReportSectionView section={section(base)} />);

    expect(await screen.findByText('Spring')).toBeInTheDocument();
    expect(screen.getByText(/10 leads carry no campaign/)).toBeInTheDocument();
  });

  it('shows an empty section as nothing recorded', async () => {
    await renderWithProviders(<ReportSectionView section={section({ ...base, total: 0 }, true)} />);

    expect(await screen.findByText('Nothing recorded')).toBeInTheDocument();
  });
});
